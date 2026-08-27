<?php

namespace Tests\Feature\Dataset;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\DatasetProduct;
use App\Services\AgentReplyService;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Rag\RetrievedChunk;
use App\Services\Rag\VectorStoreInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skipping knowledge-base retrieval on questions search_products already answers.
 *
 * The optimization is only safe because of what it does when it is WRONG, so
 * that is what most of this file asserts: the model is told the knowledge base
 * was not searched rather than that it is empty, the skip never applies without
 * the tool, and one config flag turns the whole thing off.
 */
class ProductQueryRetrievalTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeLlm(): object
    {
        $captured = new class
        {
            /** @var array<int, string> */
            public array $prompts = [];

            public int $embeds = 0;
        };

        $this->mock(LlmProviderInterface::class, function ($mock) use ($captured) {
            $mock->shouldReceive('embed')->andReturnUsing(function () use ($captured) {
                $captured->embeds++;

                return ['vector' => [1, 0, 0], 'input_tokens' => 3];
            });

            $mock->shouldReceive('generate')->andReturnUsing(
                function (string $prompt) use ($captured) {
                    $captured->prompts[] = $prompt;

                    return [
                        'text' => 'Хорошо.', 'input_tokens' => 10, 'output_tokens' => 5,
                        'finish_reason' => 'STOP', 'tool_calls' => [],
                    ];
                }
            );
        });

        return $captured;
    }

    /**
     * Counts vector-store hits so "retrieval was skipped" is asserted at the
     * store, not merely inferred from a timing.
     */
    protected function countingVectorStore(): object
    {
        $counter = new class
        {
            public int $searches = 0;
        };

        $this->mock(VectorStoreInterface::class, function ($mock) use ($counter) {
            $mock->shouldReceive('search')->andReturnUsing(function () use ($counter) {
                $counter->searches++;

                /** @var RetrievedChunk[] */
                return [];
            });
        });

        return $counter;
    }

    protected function agentWithCatalog(): Agent
    {
        $agent = Agent::factory()->create();
        $dataset = Dataset::factory()->for($agent)->create(['row_count' => 1]);

        DatasetProduct::factory()->create([
            'agent_id' => $agent->id,
            'dataset_id' => $dataset->id,
            'name' => 'Lenovo IdeaPad',
            'price' => 3200,
            'stock' => 2,
            'position' => 0,
        ]);

        return $agent;
    }

    public function test_a_pure_price_question_skips_the_embedding_and_the_vector_search(): void
    {
        $llm = $this->fakeLlm();
        $store = $this->countingVectorStore();

        $agent = $this->agentWithCatalog();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит Lenovo IdeaPad?');

        $this->assertSame(0, $llm->embeds);
        $this->assertSame(0, $store->searches);
        $this->assertTrue($reply['assistant']->meta['retrieval_skipped']);
        // Loose comparison: meta round-trips through JSON, where 0.0 comes back
        // as an int.
        $this->assertEquals(0, $reply['assistant']->meta['retrieval_ms']);
    }

    /**
     * The failure mode that would make this optimization unacceptable: a skipped
     * retrieval reading to the model as "this business has no knowledge base",
     * which would have it denying it knows anything about the business.
     */
    public function test_a_skipped_retrieval_is_never_presented_as_an_empty_knowledge_base(): void
    {
        $llm = $this->fakeLlm();
        $this->countingVectorStore();

        $agent = $this->agentWithCatalog();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит Lenovo IdeaPad?');

        $prompt = $llm->prompts[0];

        $this->assertStringContainsString('KNOWLEDGE BASE: not consulted for this message', $prompt);
        $this->assertStringContainsString('The business DOES have a knowledge base', $prompt);
        $this->assertStringNotContainsString('nothing has been uploaded for this business yet', $prompt);
    }

    public function test_a_question_that_may_need_the_knowledge_base_still_retrieves(): void
    {
        $llm = $this->fakeLlm();
        $store = $this->countingVectorStore();

        $agent = $this->agentWithCatalog();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит доставка?');

        $this->assertSame(1, $llm->embeds);
        $this->assertSame(1, $store->searches);
        $this->assertFalse($reply['assistant']->meta['retrieval_skipped']);
    }

    /**
     * Without a product source there is no tool to answer the question, so the
     * knowledge base is the only thing the agent has — skipping it would leave
     * the model with nothing at all.
     */
    public function test_an_agent_with_no_products_always_retrieves(): void
    {
        $llm = $this->fakeLlm();
        $store = $this->countingVectorStore();

        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит Lenovo IdeaPad?');

        $this->assertSame(1, $llm->embeds);
        $this->assertSame(1, $store->searches);
        $this->assertFalse($reply['assistant']->meta['retrieval_skipped']);
    }

    public function test_the_optimization_can_be_switched_off_entirely(): void
    {
        config(['agent.rag.skip_for_product_questions' => false]);

        $llm = $this->fakeLlm();
        $store = $this->countingVectorStore();

        $agent = $this->agentWithCatalog();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $reply = $this->app->make(AgentReplyService::class)->reply($conversation, 'Сколько стоит Lenovo IdeaPad?');

        $this->assertSame(1, $llm->embeds);
        $this->assertSame(1, $store->searches);
        $this->assertFalse($reply['assistant']->meta['retrieval_skipped']);
    }
}
