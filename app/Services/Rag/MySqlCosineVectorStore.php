<?php

namespace App\Services\Rag;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

            if (count($embedding) !== $queryDimensions || $model !== $activeModel) {
                $mismatched++;

                continue;
            }

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

        $best = (float) reset($scored);
        $floor = max($threshold, $relativeRatio * $best);

        $confidentIds = array_keys(array_filter($scored, fn ($score) => $score >= $floor));
        $confidentIds = array_slice($confidentIds, 0, max(1, $topK));

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
