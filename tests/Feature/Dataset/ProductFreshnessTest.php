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
 * The stale-price bug, and the instruction that exists to prevent it.
 *
 * Found in testing against a live database connection: the agent quoted a laptop
 * at 3200, the price changed to 999 in the shop's database, and asked about the
 * same laptop again it answered 3200 — from its own earlier reply, with no
 * search_products call. It only re-checked when the customer said "please
 * double-check".
 *
 * Two halves are tested here, because only one of them is fully in our control:
 *
 *  - MECHANICALLY: a second question about an already-discussed product must
 *    still reach the tool, be answered from the source, and carry the CURRENT
 *    value into the reply — no caching, no memoization, no reuse of the earlier
 *    turn's result anywhere in the pipeline. This is a hard assertion.
 *  - IN THE PROMPT: the model must be told, in terms it cannot read past, that a
 *    figure from earlier in the conversation is not a source. Whether a given
 *    model then obeys is not something a unit test can prove, so what is
 *    asserted is that the instruction is present, unambiguous, and not
 *    contradicted elsewhere in the prompt — which it was.
 */
class ProductFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected function agentWithLaptop(float $price, int $stock = 2): Agent
    {
        $agent = Agent::factory()->create();
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => 1]);

        DatasetProduct::factory()->create([
            'agent_id' => $agent->id,
            'dataset_id' => $dataset->id,
            'name' => 'Lenovo IdeaPad',
            'price' => $price,
            'stock' => $stock,
            'category' => 'Ноутбуки',
            'position' => 0,
        ]);

        return $agent;
    }

    /**
     * Same scripted-provider harness as CatalogReplyTest: a queue of generations,
     * plus a live record of every prompt and content list the model was given.
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

            public int $embeds = 0;
        };

        $this->mock(LlmProviderInterface::class, function ($mock) use ($generations, $captured) {
            $mock->shouldReceive('embed')->andReturnUsing(function () use ($captured) {
                $captured->embeds++;

                return ['vector' => [1, 0, 0], 'input_tokens' => 3];
            });

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

    /**
     * @return array<string, mixed>
     */
    protected function searchCall(string $id, string $query): array
    {
        return [
            'text' => '',
            'tool_calls' => [['id' => $id, 'name' => ProductSearchService::TOOL_NAME, 'arguments' => ['query' => $query]]],
        ];
    }

    /**
     * The reported reproduction, end to end: ask, change the price underneath,
     * ask about the SAME product again, and require a fresh lookup that returns
     * the new price.
     */
    public function test_a_second_question_about_the_same_product_searches_again_and_sees_the_new_price(): void
    {
        $captured = $this->fakeLlm([
            $this->searchCall('call-1', 'ноутбук'),
            ['text' => 'Lenovo IdeaPad — 3200 сомони, есть в наличии.'],
            $this->searchCall('call-2', 'lenovo ideapad'),
            ['text' => 'Lenovo IdeaPad стоит 999 сомони, в наличии 5 штук.'],
        ]);

        $agent = $this->agentWithLaptop(3200);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        // 1. First question — the product is discussed and its price enters the
        //    transcript as the assistant's own words.
        $first = $service->reply($conversation, 'Какие ноутбуки есть до 4000?');
        $this->assertSame(1, $first['assistant']->meta['product_searches']);
        $this->assertStringContainsString('3200', $first['assistant']->content);

        // 2. The shop changes the price and the stock underneath the conversation.
        DatasetProduct::query()->where('agent_id', $agent->id)->update(['price' => 999, 'stock' => 5]);

        // 3. The same product is asked about again.
        $second = $service->reply($conversation->refresh(), 'Сколько стоит Lenovo IdeaPad?');

        // The tool was called AGAIN — this is the bug: previously the model
        // answered from the transcript and this was 0.
        $this->assertSame(1, $second['assistant']->meta['product_searches']);

        // And the result it was handed is the CURRENT one, not the earlier one.
        // $captured->contents[3] is the generation that wrote the second reply.
        $toolResult = collect($captured->contents[3])->firstWhere('role', 'tool_result');
        $this->assertNotNull($toolResult);
        $this->assertStringContainsString('"price":"999"', $toolResult['result']);
        $this->assertStringContainsString('"stock":5', $toolResult['result']);
        $this->assertStringNotContainsString('3200', $toolResult['result']);
    }

    /**
     * The stale figure is in the transcript the model is re-reading, so the
     * instruction has to be present on that turn too — not just on the first.
     */
    public function test_the_freshness_rule_is_in_the_prompt_of_every_turn(): void
    {
        $captured = $this->fakeLlm([
            $this->searchCall('call-1', 'ноутбук'),
            ['text' => 'Lenovo IdeaPad — 3200 сомони.'],
            ['text' => 'Уточняю...'],
        ]);

        $agent = $this->agentWithLaptop(3200);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $service->reply($conversation, 'Какие ноутбуки есть до 4000?');
        $service->reply($conversation->refresh(), 'Сколько стоит Lenovo IdeaPad?');

        foreach ($captured->prompts as $index => $prompt) {
            $this->assertStringContainsString(
                'A price or stock figure that appeared EARLIER IN THIS CONVERSATION is NOT a source',
                $prompt,
                "Prompt #{$index} is missing the freshness rule.",
            );
            $this->assertStringContainsString('ALWAYS CALL IT FIRST', $prompt);
        }

        // The earlier reply really is in the transcript the model re-reads —
        // otherwise this test would pass for the wrong reason.
        $lastTurns = collect(end($captured->contents))->pluck('text')->filter()->implode(' ');
        $this->assertStringContainsString('3200', $lastTurns);
    }

    /**
     * The specific excuses the model actually used are named and refused, and
     * "wait to be asked to re-check" is called out as a failure in itself.
     */
    public function test_the_instruction_names_the_excuses_rather_than_implying_them(): void
    {
        $instruction = $this->app->make(ProductSearchService::class)->systemInstruction();

        foreach ([
            'I already looked this up above',
            'are NOT acceptable grounding',
            'how much was it again?',
            'Never wait to be asked to double-check',
            'Never announce that you are re-checking',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $instruction);
        }
    }

    /**
     * The regression that made the prompt self-contradictory: the base answering
     * policy says TIER 2 facts — prices and stock among them — may come ONLY
     * from the knowledge base. Left standing, that is a rule the model can point
     * at to justify NOT calling the tool.
     */
    public function test_the_base_policy_no_longer_contradicts_the_tool(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = $this->agentWithLaptop(3200);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит Lenovo IdeaPad?');

        $prompt = $captured->prompts[0];

        $this->assertStringContainsString('EXCEPTION, and it overrides the rule above', $prompt);
        $this->assertStringContainsString('the tool is right and the knowledge base is stale', $prompt);

        // The carve-out must appear BEFORE the catalog section it points at, so
        // the model reads the correction as part of the policy it modifies.
        $this->assertLessThan(
            strpos($prompt, 'PRODUCT CATALOG:'),
            strpos($prompt, 'EXCEPTION, and it overrides the rule above'),
        );
    }

    /**
     * An agent with no products must not be told about a tool it does not have.
     */
    public function test_the_carve_out_is_absent_without_a_product_source(): void
    {
        $captured = $this->fakeLlm([['text' => 'Здравствуйте!']]);

        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит Lenovo IdeaPad?');

        $this->assertStringNotContainsString('EXCEPTION, and it overrides the rule above', $captured->prompts[0]);
        $this->assertStringContainsString('may come ONLY from the KNOWLEDGE BASE', $captured->prompts[0]);
    }
}
