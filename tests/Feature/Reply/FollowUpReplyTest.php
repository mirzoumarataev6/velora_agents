<?php

namespace Tests\Feature\Reply;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\AgentReplyService;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Reply\ReplySimilarity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reported multi-turn failure: the assistant lists four products, the reply
 * is cut off mid-word, the customer asks "Так да или нет", and the assistant
 * sends the same list back.
 */
class FollowUpReplyTest extends TestCase
{
    use RefreshDatabase;

    protected const PRODUCT_LIST = 'У нас есть Lenovo LOQ 15 за 14999 сомони, HP Victus 16 за 18500 сомони, '
        .'Acer Aspire 3 за 6200 сомони и Asus TUF за 16200 сомони. Если вас интересует какая-то определённая модель, напишите.';

    /**
     * Fake the provider with a scripted sequence (the last generation repeats
     * once exhausted) and record what it was asked.
     *
     * @param  array<int, array<string, mixed>>  $generations
     */
    protected function fakeLlm(array $generations): object
    {
        $captured = new class
        {
            /** @var array<int, string> */
            public array $prompts = [];

            /** @var array<int, array<int, array<string, mixed>>> */
            public array $contents = [];

            /** @var array<int, array<string, mixed>> */
            public array $options = [];
        };

        $this->mock(LlmProviderInterface::class, function ($mock) use ($generations, $captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');

            $call = 0;
            $mock->shouldReceive('generate')->andReturnUsing(
                function (string $prompt, array $contents, array $options = []) use ($generations, $captured, &$call) {
                    $captured->prompts[] = $prompt;
                    $captured->contents[] = $contents;
                    $captured->options[] = $options;

                    $generation = $generations[min($call, count($generations) - 1)];
                    $call++;

                    return $generation + [
                        'text' => '', 'input_tokens' => 10, 'output_tokens' => 5,
                        'finish_reason' => 'STOP', 'tool_calls' => [],
                    ];
                }
            );
        });

        return $captured;
    }

    protected function conversationAfterAList(Agent $agent): Conversation
    {
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $conversation->messages()->create(['role' => 'user', 'content' => 'Какие ноутбуки у вас есть?']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => self::PRODUCT_LIST]);

        return $conversation;
    }

    /**
     * The bug, end to end. The model is scripted to do exactly what it did in
     * production — answer the follow-up with the list again — and the customer
     * must still not receive it.
     */
    public function test_a_short_follow_up_is_not_answered_with_the_previous_reply_again(): void
    {
        $captured = $this->fakeLlm([
            // What the model actually produced: the same list, reworded.
            ['text' => 'Есть Lenovo LOQ 15 за 14999 сомони, HP Victus 16 за 18500 сомони, Acer Aspire 3 за 6200 '
                .'сомони и Asus TUF за 16200 сомони. Напишите, если интересует определённая модель.'],
            // What it produces once told it has just repeated itself.
            ['text' => 'Да, это всё, что сейчас в наличии. Подсказать, какой из них лучше для игр?'],
        ]);

        $agent = Agent::factory()->create();
        $conversation = $this->conversationAfterAList($agent);

        $reply = $this->app->make(AgentReplyService::class)->generateReplyFor(
            $conversation,
            $conversation->messages()->create(['role' => 'user', 'content' => 'Так да или нет']),
        );

        $similarity = $this->app->make(ReplySimilarity::class);

        $this->assertFalse(
            $similarity->isNearDuplicate($reply->content, self::PRODUCT_LIST),
            'the follow-up was answered with the previous reply again',
        );
        $this->assertLessThan(0.8, $similarity->containment($reply->content, self::PRODUCT_LIST));
        $this->assertTrue($reply->meta['rewrote_repeated_reply']);
        $this->assertTrue($reply->meta['short_follow_up']);

        // The correction names the problem rather than hoping the model notices.
        $this->assertStringContainsString('YOU HAVE JUST WRITTEN THE SAME REPLY AGAIN', end($captured->prompts));
    }

    /**
     * The prompt half of the same fix: the model is told what kind of message it
     * is answering, and is shown the reply it must not send again.
     */
    public function test_a_short_follow_up_is_flagged_in_the_prompt_with_the_previous_reply(): void
    {
        $captured = $this->fakeLlm([['text' => 'Да, это всё, что есть в этом бюджете.']]);

        $agent = Agent::factory()->create();
        $conversation = $this->conversationAfterAList($agent);

        $this->app->make(AgentReplyService::class)->generateReplyFor(
            $conversation,
            $conversation->messages()->create(['role' => 'user', 'content' => 'Так да или нет']),
        );

        $prompt = $captured->prompts[0];

        $this->assertStringContainsString('THIS MESSAGE IS A SHORT FOLLOW-UP', $prompt);
        $this->assertStringContainsString('Do NOT restate what you already sent', $prompt);
        $this->assertStringContainsString(self::PRODUCT_LIST, $prompt);
    }

    /**
     * The conversation itself is what a follow-up refers to, so it has to be
     * there — a truncated or missing history is how the model loses the thread.
     */
    public function test_the_previous_exchange_is_still_in_the_history_sent_to_the_model(): void
    {
        $captured = $this->fakeLlm([['text' => 'Да, это всё.']]);

        $agent = Agent::factory()->create();
        $conversation = $this->conversationAfterAList($agent);

        $this->app->make(AgentReplyService::class)->generateReplyFor(
            $conversation,
            $conversation->messages()->create(['role' => 'user', 'content' => 'Так да или нет']),
        );

        $turns = array_column($captured->contents[0], 'text');

        $this->assertSame(['Какие ноутбуки у вас есть?', self::PRODUCT_LIST, 'Так да или нет'], $turns);
    }

    /**
     * An ordinary question is left alone: no follow-up section, no second
     * generation, no repeat check.
     */
    public function test_an_ordinary_question_is_not_treated_as_a_follow_up(): void
    {
        $captured = $this->fakeLlm([['text' => 'Да, есть несколько моделей.']]);

        $agent = Agent::factory()->create();
        $conversation = $this->conversationAfterAList($agent);

        $reply = $this->app->make(AgentReplyService::class)->generateReplyFor(
            $conversation,
            $conversation->messages()->create(['role' => 'user', 'content' => 'А есть ли у вас игровые мышки до 500 сомони?']),
        );

        $this->assertStringNotContainsString('THIS MESSAGE IS A SHORT FOLLOW-UP', $captured->prompts[0]);
        $this->assertFalse($reply->meta['short_follow_up']);
        $this->assertCount(1, $captured->prompts);
    }

    // --- truncation ----------------------------------------------------------

    /**
     * The visible symptom that started this: a reply ending mid-word. The token
     * cap is the cause, and it was sized as though the agent wrote English.
     */
    public function test_the_output_budget_is_big_enough_for_a_cyrillic_reply(): void
    {
        $captured = $this->fakeLlm([['text' => 'Да.']]);

        $agent = Agent::factory()->create(['response_format' => ['max_length' => 600]]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Есть ноутбуки?');

        // 600 characters of Cyrillic cost roughly 600 tokens, not the 200 the
        // old chars/3 estimate allowed.
        $this->assertGreaterThanOrEqual(600, $captured->options[0]['max_output_tokens']);
    }

    public function test_a_reply_cut_off_at_the_token_limit_is_trimmed_to_a_whole_sentence(): void
    {
        $this->fakeLlm([[
            'text' => 'У нас есть три модели. Если вас интересует какая-то определ',
            'finish_reason' => 'MAX_TOKENS',
        ]]);

        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Какие есть ноутбуки?');

        // The customer sees a shorter reply, never a half-written word.
        $this->assertSame('У нас есть три модели.', $reply['assistant']->content);
    }

    public function test_a_reply_that_finished_normally_is_left_exactly_as_written(): void
    {
        $this->fakeLlm([['text' => 'Есть три модели, подскажу подробнее', 'finish_reason' => 'STOP']]);

        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Какие есть ноутбуки?');

        $this->assertSame('Есть три модели, подскажу подробнее', $reply['assistant']->content);
    }

    public function test_repeated_budget_question_is_removed_without_another_generation(): void
    {
        $captured = $this->fakeLlm([['text' => 'Acer Aspire стоит 6200 сомони и подходит для документов. Какой у вас бюджет?']]);
        $conversation = Agent::factory()->create()->conversations()->create(['status' => 'ai']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Какой у вас бюджет?']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'До 7000 сомони, для документов.')['assistant'];

        $this->assertSame('Acer Aspire стоит 6200 сомони и подходит для документов.', $reply->content);
        $this->assertTrue($reply->meta['rewrote_repeated_reply']);
        $this->assertCount(1, $captured->prompts);
    }

    public function test_short_canned_reply_is_rewritten_with_the_draft_available(): void
    {
        $original = 'Уточню этот вопрос у нашего менеджера.';
        $captured = $this->fakeLlm([
            ['text' => $original],
            ['text' => 'По доставке пока нет подтверждения. Адрес магазина могу подсказать сейчас.'],
        ]);
        $conversation = Agent::factory()->create()->conversations()->create(['status' => 'ai']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => $original]);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'А что сейчас известно по доставке?')['assistant'];

        $this->assertCount(2, $captured->prompts);
        $this->assertStringContainsString(json_encode($original, JSON_UNESCAPED_UNICODE), $captured->prompts[1]);
        $this->assertStringNotContainsString($original, $reply->content);
        $this->assertTrue($reply->meta['rewrote_repeated_reply']);
        $this->assertSame(20, $reply->meta['input_tokens']);
        $this->assertSame(10, $reply->meta['output_tokens']);
    }

    public function test_requested_recap_is_allowed_without_rewriting_or_extra_cost(): void
    {
        $captured = $this->fakeLlm([['text' => self::PRODUCT_LIST]]);
        $conversation = $this->conversationAfterAList(Agent::factory()->create());

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Повтори, пожалуйста, список с ценами.')['assistant'];

        $this->assertSame(self::PRODUCT_LIST, $reply->content);
        $this->assertFalse($reply->meta['rewrote_repeated_reply']);
        $this->assertCount(1, $captured->prompts);
    }

    public function test_generic_sales_closing_is_removed_without_changing_the_verified_answer(): void
    {
        $captured = $this->fakeLlm([['text' => 'Доставка стоит 50 сомони. Как вы хотите поступить?']]);
        $conversation = Agent::factory()->create()->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит доставка?')['assistant'];

        $this->assertSame('Доставка стоит 50 сомони.', $reply->content);
        $this->assertTrue($reply->meta['style_cleaned']);
        $this->assertCount(1, $captured->prompts);
    }

    public function test_a_longer_exchange_keeps_the_original_budget_and_operator_reply_in_order(): void
    {
        $captured = $this->fakeLlm([['text' => 'В вашем бюджете подходит Acer, его и советую.']]);
        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Нужно до 7000, MacBook не предлагайте.']);
        $conversation->messages()->create(['role' => 'operator', 'content' => 'Посмотрим варианты для документов.']);
        for ($i = 0; $i < 6; $i++) {
            $conversation->messages()->create(['role' => 'user', 'content' => "Уточнение о характеристиках {$i}"]);
            $conversation->messages()->create(['role' => 'assistant', 'content' => "Ответ о характеристиках {$i}"]);
        }
        $other = $agent->conversations()->create(['status' => 'ai']);
        $other->messages()->create(['role' => 'user', 'content' => 'PRIVATE: бюджет другого клиента']);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Какой лучше взять с учетом моего бюджета?');

        $history = $captured->contents[0];
        $this->assertCount(15, $history);
        $this->assertSame('Нужно до 7000, MacBook не предлагайте.', $history[0]['text']);
        $this->assertSame(['role' => 'model', 'text' => 'Посмотрим варианты для документов.'], $history[1]);
        $this->assertStringNotContainsString('PRIVATE', json_encode($history));
    }
}
