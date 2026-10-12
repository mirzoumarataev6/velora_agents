<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\CrmStagePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * One stage in one owner's pipeline. See the creating migration's docblock
 * for the full reasoning behind `role`/`is_system`/`position`.
 */
#[Fillable(['name', 'color'])]
#[UsePolicy(CrmStagePolicy::class)]
class CrmStage extends Model
{
    use SoftDeletes;
    use StampsTeam;

    public const ROLES = ['initial', 'won', 'lost'];

    public const COLORS = CrmWorkspace::COLORS;

    /**
     * Exactly one of each role per owner, forever, and a role can never
     * change once set — both invariants live here rather than in the
     * schema, the same reasoning `Customer::booted()` already gives for its
     * own phone-key guarantee: a partial unique index would mean something
     * different on SQLite (tests) and MySQL (prod), and "can a role change"
     * is not expressible as a constraint at all.
     */
    protected static function booted(): void
    {
        static::saving(function (CrmStage $stage) {
            if ($stage->exists && $stage->isDirty('role')) {
                throw ValidationException::withMessages(['role' => __('crm.stages.role_immutable')]);
            }

            if ($stage->role !== null && static::where('user_id', $stage->user_id)
                ->where('role', $stage->role)
                ->whereKeyNot($stage->id)
                ->exists()) {
                throw ValidationException::withMessages(['role' => __('crm.stages.role_taken')]);
            }
        });

        static::deleting(function (CrmStage $stage) {
            if ($stage->role !== null) {
                throw ValidationException::withMessages(['role' => __('crm.stages.role_stage_undeletable')]);
            }
        });
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * @param  Builder<CrmStage>  $query
     * @return Builder<CrmStage>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('crm_stages.user_id', $user->id);
    }

    /**
     * Every stage the pipeline can be filtered by — the ordered run
     * (initial → intermediates → won) followed by the set-apart lost
     * stage, the same shape `Deal::STAGES + STAGE_CANCELLED` always was,
     * just per-owner and data-driven now instead of one global array.
     *
     * @param  Builder<CrmStage>  $query
     * @return Builder<CrmStage>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('position is null')->orderBy('position');
    }

    /**
     * Idempotently ensures this owner has their four role-bearing stages,
     * creating them (as a fresh, un-customized pipeline) the first time
     * they're needed — the same lazy, firstOrCreate-style guarantee
     * `CrmWorkspace::defaultFor()` already gives the default folder, for
     * accounts created after this table existed rather than backfilled
     * into it.
     *
     * @return Collection<int, CrmStage>
     */
    public static function defaultsFor(User $user): Collection
    {
        $existing = static::ownedBy($user)->ordered()->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        foreach ([
            ['name' => Deal::STAGES[0], 'role' => 'initial', 'position' => 0, 'color' => 'slate'],
            ['name' => Deal::STAGES[1], 'role' => null, 'position' => 1, 'color' => 'amber'],
            ['name' => Deal::STAGES[2], 'role' => 'won', 'position' => 2, 'color' => 'green'],
            ['name' => Deal::STAGE_CANCELLED, 'role' => 'lost', 'position' => null, 'color' => 'rose'],
        ] as $stage) {
            // forceCreate: role/position/is_system are deliberately outside
            // #[Fillable] — only name/color are ever mass-assignable from a
            // request — so seeding them needs the same bypass
            // Customer::forceCreate() uses for user_id.
            static::forceCreate($stage + ['user_id' => $user->id, 'is_system' => true]);
        }

        return static::ownedBy($user)->ordered()->get();
    }

    public static function initialFor(User $user): CrmStage
    {
        return static::defaultsFor($user)->firstWhere('role', 'initial');
    }

    public static function wonFor(User $user): CrmStage
    {
        return static::defaultsFor($user)->firstWhere('role', 'won');
    }

    public static function lostFor(User $user): CrmStage
    {
        return static::defaultsFor($user)->firstWhere('role', 'lost');
    }

    /** The owner-facing label — translated for an un-renamed default, the owner's own text otherwise. */
    public function displayName(): string
    {
        return $this->is_system ? __('crm.stages.'.$this->name) : $this->name;
    }

    /**
     * Every intermediate (role-less) stage, ordered — what
     * DealStageAutomation checks the count of before deciding whether its
     * one negotiation signal has an unambiguous target to advance into.
     *
     * @return Collection<int, CrmStage>
     */
    public static function intermediatesFor(User $user): Collection
    {
        return static::defaultsFor($user)->filter(fn (CrmStage $stage) => $stage->role === null)->values();
    }

    /**
     * The Tailwind background utility for a stage's colour dot — the exact
     * same six-colour set and class list `board.blade.php` already uses for
     * a workspace's own dot, just centralized here instead of repeated
     * inline once stages need it too.
     */
    public static function dotClass(string $color): string
    {
        return match ($color) {
            'violet' => 'bg-purple-500',
            'green' => 'bg-green-500',
            'amber' => 'bg-amber-500',
            'rose' => 'bg-rose-500',
            'slate' => 'bg-slate-500',
            default => 'bg-brand',
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'crm_stage_id');
    }
}
