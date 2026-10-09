<?php

namespace App\Models;

use App\Policies\BiDashboardPolicy;
use Database\Factories\BiDashboardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A board of widgets, owned by an account.
 *
 * Holds no data of its own beyond a name and its filters — the numbers live in
 * the datasets its widgets point at. That matters for deletion: dropping a
 * dashboard drops its widgets and nothing else, which is why this model needs
 * no observer while `BiDataset` does.
 */
#[Fillable(['name', 'description', 'filters', 'is_default', 'position'])]
#[UsePolicy(BiDashboardPolicy::class)]
class BiDashboard extends Model
{
    /** @use HasFactory<BiDashboardFactory> */
    use HasFactory;

    /** Widgets one board may hold, before it stops being a board. */
    public const MAX_WIDGETS = 40;

    /** Boards one account may keep. */
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

    /**
     * @return HasMany<BiWidget, $this>
     */
    public function widgets(): HasMany
    {
        // Grid order, which is reading order: top to bottom, then left to
        // right. The view renders in this order and gridstack re-places each
        // card from its own coordinates, so a board looks identical before and
        // after the JS loads.
        return $this->hasMany(BiWidget::class, 'bi_dashboard_id')
            ->orderBy('grid_y')
            ->orderBy('grid_x');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(BiChatMessage::class, 'bi_dashboard_id')->orderBy('id');
    }

    /**
     * @param  Builder<BiDashboard>  $query
     * @return Builder<BiDashboard>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('bi_dashboards.user_id', $user->id);
    }

    /**
     * Make this the account's landing board, demoting whichever held the title.
     *
     * Done in a transaction and with an explicit demote rather than with a
     * partial unique index, because a `UNIQUE (user_id) WHERE is_default` is
     * not portable to SQLite — which `phpunit.xml` pins the whole suite to. An
     * invariant the test suite cannot exercise is not an invariant.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::query()
                ->where('user_id', $this->user_id)
                ->whereKeyNot($this->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * The board an owner lands on: their default, else their first.
     *
     * Falls back rather than returning null so the BI section always has
     * somewhere to go — an account whose default was deleted should see their
     * next board, not an empty state that implies they lost everything.
     */
    public static function landingFor(User $user): ?self
    {
        return static::ownedBy($user)
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    /**
     * Dashboard-level filters for one dataset.
     *
     * Keyed by dataset id because a board can mix datasets, and "region =
     * Душанбе" is meaningless to a dataset with no region column. Returning
     * only the entries for the dataset being queried is what lets a mixed board
     * carry a filter at all — see FilterMerger for the merge rule.
     *
     * @return array<int, array<string, mixed>>
     */
    public function filtersFor(int $datasetId): array
    {
        $filters = $this->filters ?? [];
        $forDataset = $filters[(string) $datasetId] ?? $filters[$datasetId] ?? [];

        return is_array($forDataset) ? array_values($forDataset) : [];
    }

    /** Whether this board is at its widget ceiling. */
    public function isFull(): bool
    {
        return $this->widgets()->count() >= self::MAX_WIDGETS;
    }
}
