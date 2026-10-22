<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\KnowledgeSourcePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type', 'name', 'original_filename', 'file_path', 'url', 'mime_type',
    'status', 'error_message', 'meta',
])]
#[UsePolicy(KnowledgeSourcePolicy::class)]
class KnowledgeSource extends Model
{
    use HasFactory;
    use StampsTeam;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function sourceDirectory(): string
    {
        return "knowledge/{$this->agent_id}/sources";
    }

    public function extractedPath(): string
    {
        return "knowledge/{$this->agent_id}/extracted/{$this->id}.json";
    }
}
