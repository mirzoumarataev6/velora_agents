<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\BiDashboardPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'description', 'filters', 'is_default', 'position'])]
#[UsePolicy(BiDashboardPolicy::class)]
class BiDashboard extends Model
{
    use HasFactory;
    use ScopesActiveTeam;
    use StampsTeam;

    public const MAX_WIDGETS = 40;

    public const MAX_PER_OWNER = 20;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiSource::class, 'bi_source_id');
    }

    public function mainDataset(): BelongsTo
    {
        return $this->belongsTo(BiDataset::class, 'bi_dataset_id');
    }

    public function widgets(): HasMany
    {

        return $this->hasMany(BiWidget::class, 'bi_dashboard_id')
            ->orderBy('grid_y')
            ->orderBy('grid_x');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(BiChatMessage::class, 'bi_dashboard_id')->orderBy('id');
    }

    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::query()
                ->where('team_id', $this->team_id)
                ->whereKeyNot($this->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    public static function landingFor(User $user): ?self
    {
        return static::ownedBy($user)
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    public function filtersFor(int $datasetId): array
    {
        $filters = $this->filters ?? [];
        $forDataset = $filters[(string) $datasetId] ?? $filters[$datasetId] ?? [];

        return is_array($forDataset) ? array_values($forDataset) : [];
    }

    public function isFull(): bool
    {
        return $this->widgets()->count() >= self::MAX_WIDGETS;
    }
}
