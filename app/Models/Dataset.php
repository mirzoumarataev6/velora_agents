<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\DatasetPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'original_filename', 'row_count', 'skipped_count', 'skipped_reasons',
    'mapping', 'columns', 'imported_at',
])]
#[UsePolicy(DatasetPolicy::class)]
class Dataset extends Model
{
    use HasFactory;
    use StampsTeam;

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'columns' => 'array',
            'skipped_reasons' => 'array',
            'imported_at' => 'datetime',
        ];
    }

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
