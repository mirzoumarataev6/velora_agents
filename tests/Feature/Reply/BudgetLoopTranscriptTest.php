<?php

namespace Tests\Feature\Reply;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AgentReplyService;
use App\Services\Dataset\ProductSearchService;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Reply\ReplySimilarity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PixelShopCatalog;
use Tests\TestCase;

/**
 * The reported Tajik conversation, three turns: a 6000-7000 budget, then
 * "not MacBook, something else at that price" twice. In production each turn
 * got the same three MacBooks.
 *
 * The model is scripted to do exactly what it did then — send the MacBook list
 * again, in the very words it used — and the customer must still never
 * receive a repeat.
 */
class BudgetLoopTranscriptTest extends TestCase
{
    use PixelShopCatalog;
    use RefreshDatabase;

    protected const CUSTOMER = [
        'Ман хамин а 6000 то 7000 ноутбуки нагз мехарилакги',
        'Манба ноутбуки МакБук макул не ягонел дигархелакоша хамин нархба хайми',
        'Манба МакБук макул не хамун нархба ягонта приблизительно ев',
    ];

    /** The agent's real replies, messages 214 / 216 / 218. */
    protected const PRODUCTION_REPLIES = [
        "Маъзед, ман ноутбукҳое бо нархи 6000 то 7000 сомони наёфтам. Бо вуҷуди ин, ман баъзе модели хуби MacBook-ро мебинам, ки метавонанд барои шумо манфиатбахш бошанд:\n1. **Macbook Neo 13** — 8199 сомонӣ (8GB RAM, 256GB).\n2. **Macbook Neo 13** — 8900 сомонӣ (8GB RAM, 512GB).\n3. **Macbook Air 15** — 11999 сомонӣ (16GB RAM, 256GB).\nАгар шумо намуди дигар ноутбук ё нархҳои гуногунро гузинед, ман кӯмак кунам!",
        "Маъзед, дар нарх range васият (6000 то 7000 сомонӣ) барои ноутбукҳо ман моделе наёфтам. Ба сифати варианти наздик, ноутбукҳои MacBook бо нархҳои зерин мавҷуданд:\n1. **Macbook Neo 13** — 8199 сомонӣ (8GB RAM, 256GB).\n2. **Macbook Neo 13** — 8900 сомонӣ (8GB RAM, 512GB).\n3. **Macbook Air 15** — 11999 сомонӣ (16GB RAM, 256GB).\nАгар шумо хоҳиш дошта бошед, ман метавонам кӯмак кунам, то варианти дигари мувофиқ пайдо кунам!",
        "Маъзед, дар диапазони нархи 6000 то 7000 сомонӣ барои ноутбукҳо ман моделе наёфтам. Наздиктарин вариантҳо инҳоянд:\n1. **Macbook Neo 13** — 8199 сомонӣ (8GB RAM, 256GB).\n2. **Macbook Neo 13** — 8900 сомонӣ (8GB RAM, 512GB).\n3. **Macbook Air 15** — 11999 сомонӣ (16GB RAM, 256GB).\nАгар шумо хоҳед, ман метавонам кӯмак кунам, то варианти дигаре пайдо кунам, ки дар буҷаатон фити кунад!",
    ];

    /** What the model writes once told it has repeated itself. */
    protected const REWRITES = [
        'Дар ҳудуди 6000–7000 сомонӣ ноутбук нест. Наздиктарин — HP 15 бо Intel Core i5, 5999 сомонӣ: ҳамагӣ 1 сомонӣ '
            .'арзонтар аз ҳадди шумо. Буҷаи шумо қатъист ё каме тағйир додан мумкин аст?',
        'Ғайр аз MacBook боз як ноутбук ҳаст: 15.6" FHD бо Intel Core Ultra 7, 16GB, 7999 сомонӣ — аз ҳадди шумо 999 '
            .'сомонӣ зиёд. Ноутбукро барои чӣ истифода мебаред: таҳсил, кор ё бозӣ?',
    ];

    protected object $captured;

    protected function setUp(): void
    {
        parent::setUp();

        $search = fn (array $args) => ['tool_calls' => [[
            'id' => 'call-'.uniqid(), 'name' => ProductSearchService::TOOL_NAME, 'arguments' => $args,
        ]]];

        $budget = ['min_price' => 6000, 'max_price' => 7000];
        $notMacbook = $budget + ['exclude' => ['MacBook']];

        $this->captured = $this->fakeLlm([
            // Turn 1: search, then the production reply.
            $search($budget),
            ['text' => self::PRODUCTION_REPLIES[0]],
            // Turn 2: search, the production repeat, then — after the correction — something new.
            $search($notMacbook),
            ['text' => self::PRODUCTION_REPLIES[1]],
            ['text' => self::REWRITES[0]],
            // Turn 3: the same again, repeating turn 1 rather than turn 2.
            $search($notMacbook),
            ['text' => self::PRODUCTION_REPLIES[2]],
            ['text' => self::REWRITES[1]],
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $generations */
    protected function fakeLlm(array $generations): object
    {
        $captured = new class
        {
            /** @var array<int, string> */
            public array $prompts = [];

            /** @var array<int, array<int, array<string, mixed>>> */
            public array $contents = [];
        };

        $this->mock(LlmProviderInterface::class, function ($mock) use ($generations, $captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');

            $call = 0;
            $mock->shouldReceive('generate')->andReturnUsing(
                function (string $prompt, array $contents) use ($generations, $captured, &$call) {
                    $captured->prompts[] = $prompt;
                    $captured->contents[] = $contents;

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

    /** @return array<int, Message> The three assistant replies. */
    protected function playTranscript(): array
    {
        $agent = Agent::factory()->for(User::factory())->create();
        $this->seedPixelShop($agent);

        /** @var Conversation $conversation */
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $replies = [];
        foreach (self::CUSTOMER as $text) {
            $replies[] = $service->generateReplyFor(
                $conversation,
                $conversation->messages()->create(['role' => 'user', 'content' => $text]),
            );
        }

        return $replies;
    }

    /** The search results the model was handed, in call order. */
    protected function toolResults(): array
    {
        return collect($this->captured->contents)
            ->flatten(1)
            ->where('role', 'tool_result')
            ->unique('id')
            ->map(fn (array $content) => json_decode($content['result'], true))
            ->values()
            ->all();
    }

    public function test_three_consecutive_turns_never_repeat_each_other(): void
    {
        [$first, $second, $third] = $this->playTranscript();

        $this->assertSame(self::PRODUCTION_REPLIES[0], $first->content);
        $this->assertSame(self::REWRITES[0], $second->content);
        $this->assertSame(self::REWRITES[1], $third->content);
        $this->assertTrue($second->meta['rewrote_repeated_reply']);
        $this->assertTrue($third->meta['rewrote_repeated_reply']);

        $similarity = new ReplySimilarity;
        foreach ([[$second, $first], [$third, $second], [$third, $first]] as [$later, $earlier]) {
            $this->assertFalse(
                $similarity->isRepeatOf($later->content, $earlier->content, self::CUSTOMER),
                "Reply #{$later->id} repeats #{$earlier->id}",
            );
        }
    }

    /**
     * Turn 3's scripted reply repeats turn 1, not turn 2 (which was rewritten).
     * Comparing against only the immediately previous reply would miss it.
     */
    public function test_a_repeat_of_the_reply_before_last_is_still_caught(): void
    {
        $this->playTranscript();

        $thirdTurnCorrection = $this->captured->prompts[7];

        $this->assertStringContainsString('YOU HAVE JUST WRITTEN THE SAME REPLY AGAIN', $thirdTurnCorrection);
        $this->assertStringContainsString('Macbook Air 15** — 11999', $thirdTurnCorrection);
        $this->assertStringContainsString('nothing new to offer', $thirdTurnCorrection);
        $this->assertStringContainsString('narrows the search', $thirdTurnCorrection);
    }

    public function test_the_search_hands_the_model_the_near_miss_first_and_honours_the_exclusion(): void
    {
        $this->playTranscript();

        [$budget, $notMacbook] = $this->toolResults();

        $this->assertSame(5999.0, self::priceOf($budget['nearest'][0]));
        $this->assertSame(['side' => 'below_min', 'amount' => 1], $budget['nearest'][0]['budget_gap']);

        foreach ($notMacbook['nearest'] as $row) {
            $this->assertStringNotContainsStringIgnoringCase('macbook', $row['name']);
        }
    }

    public function test_the_prompt_carries_the_exclusion_rule_and_the_tool_can_express_it(): void
    {
        $this->playTranscript();

        $this->assertStringContainsString('WHAT THE CUSTOMER HAS REJECTED', $this->captured->prompts[0]);
        $this->assertStringContainsString('WHEN NOTHING FITS THE BUDGET', $this->captured->prompts[0]);
    }

    public function test_a_tajik_conversation_is_told_not_to_splice_in_english_or_russian(): void
    {
        $agent = Agent::factory()->for(User::factory())->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->generateReplyFor(
            $conversation,
            $conversation->messages()->create(['role' => 'user', 'content' => 'Шумо ноутбукҳо доред? Нархаш чӣ қадар аст?']),
        );

        $prompt = $this->captured->prompts[0];

        $this->assertStringContainsString('WRITE YOUR REPLY IN Tajik', $prompt);
        $this->assertStringContainsString('Do NOT splice', $prompt);
        $this->assertStringContainsString('фити кунад', $prompt);
    }
}
