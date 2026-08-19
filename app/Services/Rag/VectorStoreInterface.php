<?php

namespace App\Services\Rag;

use App\Models\Agent;

/**
 * Abstraction over similarity search so the MySQL/PHP implementation can be
 * swapped for a dedicated vector database (Qdrant, Pinecone, ...) later
 * without touching AgentReplyService or any other business logic.
 */
interface VectorStoreInterface
{
    /**
     * Find the top-K knowledge chunks for an agent whose embeddings are most
     * similar to the given query vector.
     *
     * A chunk counts as a confident match when it clears BOTH the absolute
     * `$threshold` and `$relativeRatio` of the best score in this search. The
     * relative half is what carries short and cross-lingual questions, whose
     * absolute cosine collapses even when the ranking is still correct.
     *
     * When nothing is confident, the best `$minContext` chunks are returned
     * anyway with `confident = false`, so a non-empty knowledge base never
     * reaches the model as an empty one. Pass 0 to disable that.
     *
     * @param  float[]  $queryVector
     * @return RetrievedChunk[] Ordered by score descending.
     */
    public function search(
        Agent $agent,
        array $queryVector,
        int $topK,
        float $threshold,
        float $relativeRatio = 0.0,
        int $minContext = 0,
    ): array;
}
