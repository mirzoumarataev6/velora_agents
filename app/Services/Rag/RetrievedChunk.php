<?php

namespace App\Services\Rag;

use App\Models\KnowledgeChunk;

final class RetrievedChunk
{
    public function __construct(
        public readonly KnowledgeChunk $chunk,
        public readonly float $score,

        public readonly bool $confident = true,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->chunk->id,
            'source_name' => $this->chunk->knowledgeSource?->name,
            'source_id' => $this->chunk->knowledge_source_id,
            'source_mime_type' => $this->chunk->knowledgeSource?->mime_type,
            'content' => $this->chunk->content,
            'score' => round($this->score, 4),
            'confident' => $this->confident,
        ];
    }
}
