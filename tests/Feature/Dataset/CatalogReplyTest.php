<?php

namespace Tests\Feature\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Services\AgentReplyService;
use App\Services\Dataset\ProductSearchService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reply pipeline's half of the feature: offering the tool only when there is
 * a catalog, answering the call, and writing the reply with the results in hand.
 */
class CatalogReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function agentWithCatalog(): Agent
    {
        $agent = Agent::factory()->create();
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => 2]);

        foreach ([
            ['name' => 'Куртка зимняя', 'price' => 4500, 'stock' => 3],
            ['name' => 'Шапка вязаная', 'price' => 450, 'stock' => 0],
        ] as $position => $attributes) {
            DatasetProduct::factory()->create($attributes + [
                'agent_id' => $agent->id,
                'dataset_id' => $dataset->id,
                'category' => 'Одежда',
                'position' => $position,
            ]);
        }

        return $agent;
    }

    /**
     * Fake the provider with a scripted sequence of generations (the last one
     * repeats once exhausted) and hand back a live record of what it was asked.
     *
     * An object, not an array: the mock closure has to keep writing into the
     * same instance the test then reads.
     *
     * @param  array<int, array<string, mixed>>  $generations  Returned in order.
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

    public function test_the_tool_and_its_guidance_are_offered_only_when_a_catalog_exists(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $withoutCatalog = Agent::factory()->create();
        $conversation = $withoutCatalog->conversations()->create(['status' => 'ai']);
        $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит куртка?');

        $this->assertStringNotContainsString('PRODUCT CATALOG:', $captured->prompts[0]);
        $this->assertSame(
            [],
            array_filter(
                $captured->options[0]['tools'] ?? [],
                fn ($tool) => $tool['name'] === ProductSearchService::TOOL_NAME,
            ),
        );
    }

    public function test_an_agent_with_a_catalog_is_given_the_tool(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = $this->agentWithCatalog();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит куртка?');

        $this->assertStringContainsString('PRODUCT CATALOG:', $captured->prompts[0]);
        $this->assertContains(
            ProductSearchService::TOOL_NAME,
            array_column($captured->options[0]['tools'] ?? [], 'name'),
        );
    }

    public function test_a_search_call_is_answered_and_fed_back_before_the_reply_is_written(): void
    {
        $captured = $this->fakeLlm([
            ['text' => '', 'tool_calls' => [[
                'id' => 'call-1',
                'name' => ProductSearchService::TOOL_NAME,
                'arguments' => ['query' => 'куртка'],
            ]]],
            ['text' => 'Зимняя куртка стоит 4500, есть в наличии.'],
        ]);

        $agent = $this->agentWithCatalog();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит куртка?');

        $this->assertSame('Зимняя куртка стоит 4500, есть в наличии.', $reply['assistant']->content);
        $this->assertSame(1, $reply['assistant']->meta['product_searches']);

        // The second generation saw the call and its result as real turns.
        $secondTurn = $captured->contents[1];
        $toolResult = collect($secondTurn)->firstWhere('role', 'tool_result');

        $this->assertNotNull($toolResult);
        $this->assertSame(ProductSearchService::TOOL_NAME, $toolResult['name']);
        $this->assertStringContainsString('Куртка зимняя', $toolResult['result']);
        $this->assertStringContainsString('4500', $toolResult['result']);

        // Both generations are billed, not just the last one.
        $this->assertSame(20, $reply['assistant']->meta['input_tokens']);
        $this->assertSame(10, $reply['assistant']->meta['output_tokens']);
    }

    public function test_a_model_that_keeps_searching_still_produces_a_reply(): void
    {
        // Every generation asks for another search; the loop has to stop anyway.
        $this->fakeLlm([[
            'text' => 'Секунду, уточняю...',
            'tool_calls' => [[
                'id' => 'call-loop',
                'name' => ProductSearchService::TOOL_NAME,
                'arguments' => ['query' => 'куртка'],
            ]],
        ]]);

        $agent = $this->agentWithCatalog();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит куртка?');

        $this->assertSame('Секунду, уточняю...', $reply['assistant']->content);
        $this->assertSame(2, $reply['assistant']->meta['product_searches']);
    }

    /**
     * A search that finds nothing must reach the model as an empty result with
     * guidance, so the customer gets a graceful "I couldn't find that" — never
     * an error and never the generic provider-down fallback.
     */
    public function test_a_search_with_no_matches_still_produces_a_normal_reply(): void
    {
        $captured = $this->fakeLlm([
            ['text' => '', 'tool_calls' => [[
                'id' => 'call-1',
                'name' => ProductSearchService::TOOL_NAME,
                'arguments' => ['query' => 'холодильник'],
            ]]],
            ['text' => 'Холодильников у нас нет, но могу уточнить у коллеги.'],
        ]);

        $agent = $this->agentWithCatalog();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Есть холодильник?');

        $this->assertSame('Холодильников у нас нет, но могу уточнить у коллеги.', $reply['assistant']->content);
        $this->assertFalse($reply['assistant']->meta['failed']);

        $toolResult = collect($captured->contents[1])->firstWhere('role', 'tool_result');
        $this->assertStringContainsString('"count":0', $toolResult['result']);
    }

    /**
     * The search tool must not swallow a lead the model recorded on the same
     * turn — that generation is superseded, but the lead it asked for is not.
     */
    public function test_a_lead_recorded_alongside_a_search_survives_the_round_trip(): void
    {
        $this->fakeLlm([
            ['text' => '', 'tool_calls' => [
                ['id' => 'call-1', 'name' => ProductSearchService::TOOL_NAME, 'arguments' => ['query' => 'куртка']],
                ['id' => 'call-2', 'name' => 'create_lead', 'arguments' => ['customer_name' => 'Анна', 'contact' => '+992900000000']],
            ]],
            ['text' => 'Записал ваш заказ, Анна.'],
        ]);

        $agent = $this->agentWithCatalog();
        $agent->update(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Хочу куртку, я Анна, +992900000000');

        $this->assertTrue($reply['assistant']->meta['lead_captured']);
        $this->assertDatabaseHas('leads', ['agent_id' => $agent->id, 'customer_name' => 'Анна']);
        // Exactly one lead — the superseded generation's call must not be
        // counted a second time alongside the final generation's.
        $this->assertSame(1, $agent->leads()->count());
    }
}
