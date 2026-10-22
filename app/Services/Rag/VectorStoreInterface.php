<?php

namespace App\Services\Rag;

use App\Models\Agent;

interface VectorStoreInterface
{
    public function search(
        Agent $agent,
        array $queryVector,
        int $topK,
        float $threshold,
        float $relativeRatio = 0.0,
        int $minContext = 0,
    ): array;
}
