<?php

namespace App\Services\Rag;

use App\Models\KnowledgeChunk;

/**
 * A knowledge chunk paired with its cosine similarity score against a query.
 */
final class RetrievedChunk
{
    public function __construct(
        public readonly KnowledgeChunk $chunk,
        public readonly float $score,
        /**
         * Whether this chunk cleared the relevance bars, or is only here
         * because the search would otherwise have returned nothing. The prompt
         * labels the two differently so the model knows which it is reading.
         */
        public readonly bool $confident = true,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->chunk->id,
            'source_name' => $this->chunk->knowledgeSource?->name,
            'content' => $this->chunk->content,
            'score' => round($this->score, 4),
            'confident' => $this->confident,
        ];
    }
}
