<?php

namespace Tests\Feature\Leads;

use App\Models\Agent;
use App\Services\AgentReplyService;
use App\Services\LeadCaptureService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The invariant this file exists to hold: if a customer is told their order is
 * placed, an order row exists.
 *
 * It is written from a real conversation in which that was violated three
 * times. Two of the confirmations came from tool calls whose insert failed on a
 * column that had not been migrated; the third came from no tool call at all —
 * the model simply wrote "Не проблема! Мы все равно оформим вашу заявку"
 * because it was the reassuring thing to say. From the customer's side all
 * three were identical, and the orders table was empty after every one.
 */
class PromisedOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function toolCall(array $arguments): array
    {
        return [
            'text' => '', 'input_tokens' => 10, 'output_tokens' => 5, 'finish_reason' => 'tool_calls',
            'tool_calls' => [['id' => 'c1', 'name' => LeadCaptureService::TOOL_NAME, 'arguments' => $arguments]],
        ];
    }

    protected function text(string $text): array
    {
        return ['text' => $text, 'input_tokens' => 8, 'output_tokens' => 4, 'finish_reason' => 'STOP', 'tool_calls' => []];
    }

    /**
     * @param  array<int, array<string, mixed>>  $generations
     */
    protected function mockLlm(array $generations): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($generations) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');

            $call = 0;
            $mock->shouldReceive('generate')->andReturnUsing(function () use ($generations, &$call) {
                $generation = $generations[min($call, count($generations) - 1)];
                $call++;

                return $generation;
            });
        });
    }

    protected function agent(): Agent
    {
        return Agent::factory()->create(['capture_leads' => true, 'language' => 'auto']);
    }

    // --- the transcript, replayed --------------------------------------------

    /**
     * The Lenovo half: the customer refuses to give a number, in Russian and
     * then in Tajik, and the assistant confirms the order. An order must exist,
     * with no phone on it.
     */
    public function test_the_lenovo_order_is_recorded_after_the_customer_refuses_to_give_a_number(): void
    {
        Queue::fake();

        $this->mockLlm([
            // First attempt: no number anywhere, so the pipeline asks.
            $this->toolCall(['items' => 'Lenovo Legion Pro 5']),
            $this->text('Чтобы мы могли с вами связаться, оставьте, пожалуйста, номер телефона.'),
            // The customer refuses; the model records it anyway and confirms.
            $this->toolCall(['items' => 'Lenovo Legion Pro 5', 'note' => 'Номер давать отказался']),
            $this->text('Хуб, заявка қабул шуд! Мо бо шумо дар ин чат алоқа хоҳем гирифт.'),
        ]);

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $service->reply($conversation, 'Я хочу заказать ЛенВО легион про');
        // Nothing is recorded while the number is still being asked for.
        $this->assertDatabaseCount('leads', 0);

        $reply = $service->reply($conversation, 'Ман раками худро дода наметавонам')['assistant'];

        $lead = $agent->leads()->sole();

        $this->assertNull($lead->phone);
        $this->assertTrue($lead->isChatOnly());
        $this->assertSame('Lenovo Legion Pro 5', $lead->items);
        $this->assertStringContainsString('қабул шуд', $reply->content);
        $this->assertTrue($reply->meta['lead_captured']);
    }

    /**
     * The second order in the same conversation — the MacBook. A customer who
     * orders twice must produce two rows.
     */
    public function test_a_second_order_in_the_same_conversation_is_recorded_too(): void
    {
        Queue::fake();

        $this->mockLlm([
            $this->toolCall(['items' => 'Lenovo Legion Pro 5']),
            $this->text('Оставьте, пожалуйста, номер телефона.'),
            $this->toolCall(['items' => 'Lenovo Legion Pro 5']),
            $this->text('Хорошо, ваша заявка принята! Мы свяжемся с вами здесь, в чате.'),
            $this->toolCall(['items' => 'MacBook Pro 14']),
            $this->text('Не проблема! Мы все равно оформим вашу заявку без номера телефона.'),
        ]);

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $service->reply($conversation, 'Я хочу заказать ЛенВО легион про');
        $service->reply($conversation, 'Не могу');
        $service->reply($conversation, 'Я ещё хочу купить мак, MacBook Pro 14, номер не дам');

        $this->assertSame(
            ['Lenovo Legion Pro 5', 'MacBook Pro 14'],
            $agent->leads()->orderBy('id')->pluck('items')->all(),
        );
        $this->assertSame(0, $agent->leads()->whereNotNull('phone')->count());
    }

    // --- the guard -----------------------------------------------------------

    /**
     * The third confirmation had no tool call behind it at all — one
     * generation, no create_lead, and a sentence telling the customer their
     * order was accepted. The claim now creates the row it promised.
     */
    public function test_a_confirmation_with_no_tool_call_still_produces_an_order(): void
    {
        Queue::fake();

        $this->mockLlm([$this->text('Не проблема! Мы все равно оформим вашу заявку без номера телефона.')]);

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Оформите заявку, номер не дам')['assistant'];

        $lead = $agent->leads()->sole();

        $this->assertNull($lead->phone);
        $this->assertSame($conversation->id, $lead->conversation_id);
        // Thin, but real and traceable: the details are in the chat it links to.
        $this->assertTrue($lead->details['auto_recorded']);
        $this->assertStringContainsString('оформим вашу заявку', $reply->content);
    }

    public function test_an_ordinary_reply_does_not_invent_an_order(): void
    {
        Queue::fake();

        $this->mockLlm([$this->text('Чтобы оформить заказ, оставьте, пожалуйста, номер телефона.')]);

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Хочу заказать ноутбук');

        $this->assertDatabaseCount('leads', 0);
    }

    /** "Оформите ещё раз" is the same order being asked about, not a second one. */
    public function test_a_repeated_confirmation_does_not_duplicate_the_order(): void
    {
        Queue::fake();

        $this->mockLlm([$this->text('Хорошо, ваша заявка принята!')]);

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $service->reply($conversation, 'Оформите заявку');
        $service->reply($conversation, 'Можете ещё раз оформить заявку');

        $this->assertSame(1, $agent->leads()->count());
    }

    /**
     * The failure that started all this: the write itself is broken. The
     * customer must NOT be told the order is placed.
     */
    public function test_a_broken_write_withdraws_the_confirmation_instead_of_lying(): void
    {
        Queue::fake();

        $this->mockLlm([
            $this->toolCall(['phone' => '+992901234567', 'items' => 'Lenovo Legion Pro 5']),
            $this->text('Хорошо, ваша заявка принята! Мы вам позвоним.'),
        ]);

        // The exact production fault: a column the insert needs is not there.
        Schema::table('leads', fn ($table) => $table->dropColumn('phone'));

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Беру Lenovo Legion Pro 5, мой номер +992901234567')['assistant'];

        $this->assertDatabaseCount('leads', 0);
        $this->assertStringNotContainsString('заявка принята', $reply->content);
        $this->assertStringContainsString('коллеге', $reply->content);
        // A person is brought in, because an order was lost.
        $this->assertSame('human_takeover', $conversation->refresh()->status);
    }

    // --- the bare "." --------------------------------------------------------

    /**
     * A reply with nothing in it must never reach the customer. The transcript
     * shows what an unanswered turn looks like from their side: "Алооо", then
     * ".", then "." again.
     */
    public function test_an_empty_reply_is_replaced_with_the_fallback(): void
    {
        Queue::fake();

        $agent = Agent::factory()->create([
            'capture_leads' => false,
            'response_format' => ['fallback_message' => 'Секунду, уточняю и вернусь с ответом.'],
        ]);

        foreach (['.', '   ', "\n\n", '...', '!!'] as $empty) {
            $this->mockLlm([$this->text($empty)]);

            $conversation = $agent->conversations()->create(['status' => 'ai']);
            $reply = $this->app->make(AgentReplyService::class)
                ->reply($conversation, 'Ха хамун Леново легиона кабул кунед')['assistant'];

            $this->assertSame('Секунду, уточняю и вернусь с ответом.', $reply->content);
        }
    }

    /**
     * The narrow path that could produce a lone "." from a real reply: the
     * truncation repair cutting back to a leading full stop.
     */
    public function test_truncation_repair_never_reduces_a_reply_to_punctuation(): void
    {
        Queue::fake();

        $this->mockLlm([[
            'text' => '. Извините, я хотел сказать что этот ноутбук',
            'input_tokens' => 8, 'output_tokens' => 4, 'finish_reason' => 'MAX_TOKENS', 'tool_calls' => [],
        ]]);

        $agent = Agent::factory()->create(['capture_leads' => false]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Алооо')['assistant'];

        $this->assertNotSame('.', $reply->content);
        $this->assertMatchesRegularExpression('/\p{L}/u', $reply->content);
    }

    public function test_no_assistant_reply_is_ever_only_punctuation(): void
    {
        Queue::fake();

        $this->mockLlm([$this->text('.')]);

        $agent = Agent::factory()->create(['capture_leads' => false]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->reply($conversation, '.');

        foreach ($conversation->messages()->where('role', 'assistant')->pluck('content') as $reply) {
            $this->assertMatchesRegularExpression('/\p{L}|\p{N}/u', $reply);
        }
    }
}
