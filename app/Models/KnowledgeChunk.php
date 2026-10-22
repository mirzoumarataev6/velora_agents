<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\KnowledgeChunkPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content', 'embedding', 'embedding_model', 'token_count', 'chunk_index'])]
#[UsePolicy(KnowledgeChunkPolicy::class)]
class KnowledgeChunk extends Model
{
    use HasFactory;
    use StampsTeam;

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'token_count' => 'integer',
            'chunk_index' => 'integer',
        ];
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class);
    }

    public function embeddingText(): string
    {
        $name = trim((string) ($this->knowledgeSource?->name ?? ''));

        if ($name === '' || filter_var($name, FILTER_VALIDATE_URL) !== false) {
            return $this->content;
        }

        return $name."\n\n".$this->content;
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
