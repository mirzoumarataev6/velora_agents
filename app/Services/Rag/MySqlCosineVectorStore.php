<?php

namespace App\Services\Rag;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Computes cosine similarity in PHP over the agent's chunk embeddings.
 *
 * Embeddings are decoded once per agent and cached (see agent.rag.cache_ttl_seconds)
 * to avoid re-decoding the JSON embedding column on every message. The cache is
 * invalidated whenever a source finishes embedding or a chunk is deleted.
 */
class MySqlCosineVectorStore implements VectorStoreInterface
{
    public function search(
        Agent $agent,
        array $queryVector,
        int $topK,
        float $threshold,
        float $relativeRatio = 0.0,
        int $minContext = 0,
    ): array {
        $vectors = $this->loadVectors($agent);

        if ($vectors === []) {
            return [];
        }

        $queryDimensions = count($queryVector);
        $activeModel = app(LlmProviderInterface::class)->embeddingModel();
        $scored = [];
        $mismatched = 0;

        foreach ($vectors as $id => ['vector' => $embedding, 'model' => $model]) {
            // A chunk embedded under a different provider, a different output
            // dimensionality, or a different model of the same width is not
            // comparable to this query vector. Comparing them anyway produces
            // plausible-looking but meaningless scores, so skip instead —
            // retrieval degrades to "nothing found" until `embeddings:reindex`
            // re-embeds under the active model.
            //
            // The model check matters independently of the length check:
            // text-embedding-3-small and -3-large are both 1536-dimensional
            // here and share no vector space at all, so length alone would wave
            // the stale vectors through. A chunk with no recorded model predates
            // that column and cannot be vouched for either.
            if (count($embedding) !== $queryDimensions || $model !== $activeModel) {
                $mismatched++;

                continue;
            }

            // Score everything; relevance is decided below, once the best score
            // in this search is known.
            $scored[$id] = $this->cosineSimilarity($queryVector, $embedding);
        }

        if ($mismatched > 0) {
            Log::warning('Skipped knowledge chunks embedded under a different model or dimensionality', [
                'agent_id' => $agent->id,
                'active_model' => $activeModel,
                'query_dimensions' => $queryDimensions,
                'skipped' => $mismatched,
                'total' => count($vectors),
                'hint' => 'Run `php artisan embeddings:reindex --agent='.$agent->id.'` after changing the embedding model or provider.',
            ]);
        }

        if ($scored === []) {
            return [];
        }

        arsort($scored);

        // Relevance is judged against the best hit as well as the fixed floor.
        // Absolute cosine drops sharply when a two-word question meets a
        // 2,700-character chunk, and drops again across languages, so a fixed
        // floor on its own rejects knowledge bases that plainly hold the answer.
        // Ranking survives both effects, which is what the ratio reads.
        $best = (float) reset($scored);
        $floor = max($threshold, $relativeRatio * $best);

        $confidentIds = array_keys(array_filter($scored, fn ($score) => $score >= $floor));
        $confidentIds = array_slice($confidentIds, 0, max(1, $topK));

        // Nothing convincing — hand over the best few anyway, marked as such.
        // The model can disregard a weak chunk; it cannot use one it never saw.
        // This is what keeps a stocked knowledge base from presenting as empty.
        $fallbackIds = [];

        if (count($confidentIds) < $minContext) {
            $fallbackIds = array_slice(
                array_values(array_diff(array_keys($scored), $confidentIds)),
                0,
                $minContext - count($confidentIds)
            );
        }

        $topIds = array_merge($confidentIds, $fallbackIds);
        $confident = array_flip($confidentIds);

        $chunks = KnowledgeChunk::with('knowledgeSource')->whereIn('id', $topIds)->get()->keyBy('id');

        return collect($topIds)
            ->filter(fn ($id) => $chunks->has($id))
            ->map(fn ($id) => new RetrievedChunk($chunks[$id], $scored[$id], isset($confident[$id])))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{vector: float[], model: ?string}> Keyed by chunk id.
     */
    protected function loadVectors(Agent $agent): array
    {
        $ttl = (int) config('agent.rag.cache_ttl_seconds');

        return Cache::remember(self::cacheKey($agent->id), $ttl, function () use ($agent) {
            return KnowledgeChunk::where('agent_id', $agent->id)
                ->whereNotNull('embedding')
                ->get(['id', 'embedding', 'embedding_model'])
                ->mapWithKeys(fn (KnowledgeChunk $chunk) => [
                    $chunk->id => ['vector' => $chunk->embedding, 'model' => $chunk->embedding_model],
                ])
                ->all();
        });
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));

        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    public static function cacheKey(int $agentId): string
    {
        return "agent:{$agentId}:vectors";
    }

    public static function forgetCache(int $agentId): void
    {
        Cache::forget(self::cacheKey($agentId));
    }
}
