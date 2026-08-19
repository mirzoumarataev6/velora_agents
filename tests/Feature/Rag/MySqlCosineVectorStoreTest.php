<?php

namespace Tests\Feature\Rag;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Rag\MySqlCosineVectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MySqlCosineVectorStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The model defaults to whichever one is configured, i.e. a chunk that is
     * current. Pass an explicit value to simulate a stale vector left behind by
     * a model switch.
     */
    protected function makeChunk(
        Agent $agent,
        KnowledgeSource $source,
        string $content,
        array $embedding,
        ?string $model = null,
    ): KnowledgeChunk {
        $chunk = new KnowledgeChunk([
            'content' => $content,
            'embedding' => $embedding,
            'embedding_model' => $model ?? app(LlmProviderInterface::class)->embeddingModel(),
            'chunk_index' => 0,
        ]);
        $chunk->agent_id = $agent->id;
        $chunk->knowledge_source_id = $source->id;
        $chunk->save();

        return $chunk;
    }

    public function test_it_returns_top_k_ordered_by_cosine_similarity_above_threshold(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        // Query vector is [1, 0, 0]. Cosine similarities against it are known by construction:
        $exactMatch = $this->makeChunk($agent, $source, 'exact match', [1, 0, 0]);       // cos = 1.0
        $closeMatch = $this->makeChunk($agent, $source, 'close match', [0.8, 0.6, 0]);   // cos = 0.8
        $orthogonal = $this->makeChunk($agent, $source, 'orthogonal', [0, 1, 0]);        // cos = 0.0
        $opposite = $this->makeChunk($agent, $source, 'opposite', [-1, 0, 0]);           // cos = -1.0

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0], topK: 3, threshold: 0.5);

        // Only the two chunks above the 0.5 threshold should be returned, ordered best-first.
        $this->assertCount(2, $results);
        $this->assertSame($exactMatch->id, $results[0]->chunk->id);
        $this->assertEqualsWithDelta(1.0, $results[0]->score, 0.0001);
        $this->assertSame($closeMatch->id, $results[1]->chunk->id);
        $this->assertEqualsWithDelta(0.8, $results[1]->score, 0.0001);

        $returnedIds = collect($results)->pluck('chunk.id');
        $this->assertFalse($returnedIds->contains($orthogonal->id));
        $this->assertFalse($returnedIds->contains($opposite->id));
    }

    public function test_top_k_limit_is_respected_even_with_more_matches_available(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        $this->makeChunk($agent, $source, 'a', [1, 0, 0]);
        $this->makeChunk($agent, $source, 'b', [0.99, 0.14, 0]);
        $this->makeChunk($agent, $source, 'c', [0.95, 0.31, 0]);
        $this->makeChunk($agent, $source, 'd', [0.9, 0.44, 0]);

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0], topK: 2, threshold: 0.0);

        $this->assertCount(2, $results);
        $this->assertGreaterThanOrEqual($results[1]->score, $results[0]->score);
    }

    public function test_it_returns_empty_when_agent_has_no_embedded_chunks(): void
    {
        $agent = Agent::factory()->create();

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0], topK: 5, threshold: 0.5);

        $this->assertSame([], $results);
    }

    public function test_it_scopes_search_to_the_given_agent(): void
    {
        $agentA = Agent::factory()->create();
        $agentB = Agent::factory()->create();
        $sourceA = KnowledgeSource::factory()->for($agentA)->create();
        $sourceB = KnowledgeSource::factory()->for($agentB)->create();

        $this->makeChunk($agentA, $sourceA, 'agent A chunk', [1, 0, 0]);
        $this->makeChunk($agentB, $sourceB, 'agent B chunk', [1, 0, 0]);

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agentA, [1, 0, 0], topK: 10, threshold: 0.5);

        $this->assertCount(1, $results);
        $this->assertSame('agent A chunk', $results[0]->chunk->content);
    }

    public function test_it_skips_chunks_whose_embedding_dimensions_differ_from_the_query(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        // Simulates a provider switch: an old 3-dim vector left over from the
        // previous provider alongside a freshly reindexed 4-dim vector. Only the
        // one matching the 4-dim query is comparable.
        $this->makeChunk($agent, $source, 'stale gemini-dim chunk', [1, 0, 0]);
        $current = $this->makeChunk($agent, $source, 'reindexed chunk', [1, 0, 0, 0]);

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0, 0], topK: 10, threshold: 0.5);

        $this->assertCount(1, $results);
        $this->assertSame($current->id, $results[0]->chunk->id);
    }

    public function test_it_returns_nothing_when_every_chunk_dimension_mismatches(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        // All chunks still hold the previous provider's dimensionality.
        $this->makeChunk($agent, $source, 'stale', [1, 0, 0]);

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0, 0], topK: 10, threshold: 0.5);

        $this->assertSame([], $results);
    }

    /**
     * Two models can agree on dimensionality and still share no vector space —
     * text-embedding-3-small and -3-large are both 1536-dimensional as
     * configured here. Length alone would wave the stale vectors through and
     * score them confidently against coordinates that mean something else.
     */
    public function test_it_skips_chunks_embedded_under_a_different_model_of_the_same_width(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        $stale = $this->makeChunk($agent, $source, 'stale model', [1, 0, 0], 'some-older-embedding-model');
        $current = $this->makeChunk($agent, $source, 'current model', [1, 0, 0]);

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0], topK: 10, threshold: 0.5);

        $this->assertCount(1, $results);
        $this->assertSame($current->id, $results[0]->chunk->id);
        $this->assertNotContains($stale->id, collect($results)->pluck('chunk.id')->all());
    }

    public function test_chunks_with_no_recorded_model_are_not_trusted(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        // Predates the embedding_model column, so nothing vouches for which
        // space it was embedded in.
        $this->makeChunk($agent, $source, 'legacy', [1, 0, 0], model: '');
        KnowledgeChunk::query()->update(['embedding_model' => null]);

        $store = new MySqlCosineVectorStore;

        $this->assertSame([], $store->search($agent, [1, 0, 0], topK: 10, threshold: 0.5));
    }

    /**
     * The regression that made a stocked knowledge base look empty: a short
     * question against long chunks scores far below any fixed floor even when
     * the ranking is perfectly correct. Judging relevance relative to the best
     * hit is what recovers it.
     */
    public function test_lowered_floor_plus_relative_ratio_recovers_matches_the_old_threshold_rejected(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        // These scores are the shape the real corpus produced: a correct
        // ranking whose absolute values all sit under the old 0.35 floor,
        // because a short question was being compared against long chunks.
        $best = $this->makeChunk($agent, $source, 'best', [0.3, 0.9539, 0]);       // cos ≈ 0.30
        $near = $this->makeChunk($agent, $source, 'near', [0.25, 0.9682, 0]);      // cos ≈ 0.25
        $far = $this->makeChunk($agent, $source, 'far', [0.05, 0.9987, 0]);        // cos ≈ 0.05

        $store = new MySqlCosineVectorStore;

        // Old settings: the floor rejected a knowledge base that held the
        // answer, and the agent reported having no information.
        $this->assertSame([], $store->search($agent, [1, 0, 0], topK: 5, threshold: 0.35));

        // New settings: the two on-topic chunks come back confident, and the
        // relative rule is what still keeps the unrelated one out — the lowered
        // floor alone (0.05 > 0.22 is false here, but 0.6 × 0.30 = 0.18 is the
        // binding constraint for anything between the two) would be sloppier.
        $results = $store->search($agent, [1, 0, 0], topK: 5, threshold: 0.22, relativeRatio: 0.6);

        $ids = collect($results)->where('confident', true)->pluck('chunk.id')->all();
        $this->assertContains($best->id, $ids);
        $this->assertContains($near->id, $ids);
        $this->assertNotContains($far->id, $ids);
    }

    /**
     * Both bars must hold for a chunk to be called confident. The relative rule
     * widens the net among comparable hits; it does not promote something the
     * absolute floor already judged irrelevant.
     */
    public function test_relative_ratio_does_not_promote_chunks_below_the_absolute_floor(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        // Everything here is near-orthogonal to the query: correct ranking,
        // but nothing genuinely on topic.
        $this->makeChunk($agent, $source, 'weak best', [0.10, 0.9950, 0]);
        $this->makeChunk($agent, $source, 'weak next', [0.09, 0.9959, 0]);

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0], topK: 5, threshold: 0.22, relativeRatio: 0.6, minContext: 2);

        // Returned so the model is not left empty-handed, but not dressed up as
        // relevant just because one of them ranked first.
        $this->assertCount(2, $results);
        $this->assertEmpty(collect($results)->where('confident', true));
    }

    public function test_it_returns_weak_context_rather_than_nothing_when_no_chunk_qualifies(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        $this->makeChunk($agent, $source, 'a', [0.05, 0.9987, 0]);
        $this->makeChunk($agent, $source, 'b', [0.04, 0.9992, 0]);
        $this->makeChunk($agent, $source, 'c', [0.03, 0.9995, 0]);
        $this->makeChunk($agent, $source, 'd', [0.02, 0.9998, 0]);

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0], topK: 10, threshold: 0.9, relativeRatio: 0.95, minContext: 3);

        // Nothing is good enough to be confident about, but a knowledge base
        // with content in it must never reach the model as an empty one.
        $this->assertCount(3, $results);
        $this->assertEmpty(collect($results)->where('confident', true));
        $this->assertSame(['a', 'b', 'c'], collect($results)->pluck('chunk.content')->all());
    }

    public function test_weak_top_up_does_not_displace_confident_matches(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        $exact = $this->makeChunk($agent, $source, 'exact', [1, 0, 0]);
        $this->makeChunk($agent, $source, 'weak one', [0.05, 0.9987, 0]);
        $this->makeChunk($agent, $source, 'weak two', [0.04, 0.9992, 0]);

        $store = new MySqlCosineVectorStore;
        $results = $store->search($agent, [1, 0, 0], topK: 10, threshold: 0.5, relativeRatio: 0.6, minContext: 3);

        $this->assertCount(3, $results);
        $this->assertSame($exact->id, $results[0]->chunk->id);
        $this->assertTrue($results[0]->confident);
        $this->assertFalse($results[1]->confident);
        $this->assertFalse($results[2]->confident);
    }

    public function test_min_context_cannot_invent_chunks_for_an_empty_knowledge_base(): void
    {
        $agent = Agent::factory()->create();

        $store = new MySqlCosineVectorStore;

        $this->assertSame([], $store->search($agent, [1, 0, 0], topK: 5, threshold: 0.5, relativeRatio: 0.6, minContext: 3));
    }
}
