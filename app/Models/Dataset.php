<?php

namespace App\Models;

use App\Policies\DatasetPolicy;
use Database\Factories\DatasetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agent's structured product catalog: one row per agent, describing the file
 * it came from and the column mapping used to import it.
 *
 * Deliberately separate from KnowledgeSource. The knowledge base stores text for
 * semantic retrieval and cannot answer "under 500 and in stock"; this stores
 * products row-per-product so the agent can filter them exactly.
 */
#[Fillable([
    'original_filename', 'row_count', 'skipped_count', 'skipped_reasons',
    'mapping', 'columns', 'imported_at',
])]
#[UsePolicy(DatasetPolicy::class)]
class Dataset extends Model
{
    /** @use HasFactory<DatasetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'columns' => 'array',
            'skipped_reasons' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    /** Advisory only — see config('agent.dataset.stale_after_days'); never read by the agent or shown to a customer. */
    public function isStale(): bool
    {
        return $this->imported_at !== null
            && $this->imported_at->lt(now()->subDays((int) config('agent.dataset.stale_after_days')));
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(DatasetProduct::class);
    }
}
