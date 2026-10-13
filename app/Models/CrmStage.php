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

            if ($stage->role !== null) {
                // Scoped by team_id rather than user_id whenever the stage
                // has one: two of this user's teams must each get their own
                // 'initial'/'won'/'lost' stage without tripping each
                // other's uniqueness, the way a single-team account's
                // stages (team_id already resolved by StampsTeam) would
                // otherwise collide with a second team's defaultsFor()
                // call. A stage still legacy (team_id NULL) keeps the old,
                // user_id-scoped check exactly as before.
                $duplicate = $stage->team_id !== null
                    ? static::where('team_id', $stage->team_id)->where('role', $stage->role)->whereKeyNot($stage->id)->exists()
                    : static::where('user_id', $stage->user_id)->where('role', $stage->role)->whereKeyNot($stage->id)->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['role' => __('crm.stages.role_taken')]);
                }
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
     * Team-scoped, not user-scoped — see CrmWorkspace::scopeOwnedBy() for
     * the full reasoning (behaviour-neutral today, never falls back to
     * user_id on a null/stale active team).
     *
     * @param  Builder<CrmStage>  $query
     * @return Builder<CrmStage>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('crm_stages.team_id', $teamId)
            : $query->whereRaw('1 = 0');
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

    /** @return list<array<string, mixed>> */
    private static function seedDefinitions(): array
    {
        return [
            ['name' => Deal::STAGES[0], 'role' => 'initial', 'position' => 0, 'color' => 'slate'],
            ['name' => Deal::STAGES[1], 'role' => null, 'position' => 1, 'color' => 'amber'],
            ['name' => Deal::STAGES[2], 'role' => 'won', 'position' => 2, 'color' => 'green'],
            ['name' => Deal::STAGE_CANCELLED, 'role' => 'lost', 'position' => null, 'color' => 'rose'],
        ];
    }

    /**
     * Idempotently ensures this owner has their four role-bearing stages,
     * creating them (as a fresh, un-customized pipeline) the first time
     * they're needed — the same lazy, firstOrCreate-style guarantee
     * `CrmWorkspace::defaultFor()` already gives the default folder, for
     * accounts created after this table existed rather than backfilled
     * into it.
     *
     * `$team` is optional and additive, same reasoning as
     * `CrmWorkspace::defaultFor()`: `null` is byte-for-byte today's
     * behaviour (scoped by `user_id` alone). Passed a team, this seeds (or
     * finds) that team's OWN four stages, scoped by `team_id` — unlike
     * `crm_workspaces`, there is no hard uniqueness constraint stopping a
     * second set from existing side by side with a legacy, still-NULL-team
     * one, so no adoption step is needed here the way `defaultFor()` needs
     * one; a brand new team-scoped set is simply created.
     *
     * @return Collection<int, CrmStage>
     */
    public static function defaultsFor(User $user, Team|int|null $team = null): Collection
    {
        if ($team === null) {
            // where('user_id', ...) directly, deliberately NOT scopeOwnedBy():
            // that scope now reads the caller's ACTIVE team (Stage 2d-4), but
            // this branch is reached by background call sites passing no
            // team at all alongside ones mid-adoption of the explicit-team
            // parameter — its existence check must key off the same user_id
            // it always has, independent of whichever team the signed-in
            // owner happens to be looking at right now.
            $existing = static::where('user_id', $user->id)->ordered()->get();

            if ($existing->isNotEmpty()) {
                return $existing;
            }

            foreach (static::seedDefinitions() as $stage) {
                // forceCreate: role/position/is_system are deliberately
                // outside #[Fillable] — only name/color are ever
                // mass-assignable from a request — so seeding them needs
                // the same bypass Customer::forceCreate() uses for user_id.
                static::forceCreate($stage + ['user_id' => $user->id, 'is_system' => true]);
            }

            return static::where('user_id', $user->id)->ordered()->get();
        }

        $teamId = $team instanceof Team ? $team->id : $team;

        // Same reasoning as the null-team branch above: a direct where(),
        // not scopeOwnedBy() — $teamId here is whatever team the caller
        // resolved (the agent's own team, say), which can legitimately
        // differ from the signed-in owner's ACTIVE team that scopeOwnedBy()
        // now reads.
        $existing = static::where('user_id', $user->id)->where('team_id', $teamId)->ordered()->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        foreach (static::seedDefinitions() as $stage) {
            // team_id set explicitly, same reason as CrmWorkspace::defaultFor():
            // never left to StampsTeam's own user_id fallback, which would
            // resolve through current_team_id instead of the team actually
            // being seeded for.
            static::forceCreate($stage + ['user_id' => $user->id, 'is_system' => true, 'team_id' => $teamId]);
        }

        return static::where('user_id', $user->id)->where('team_id', $teamId)->ordered()->get();
    }

    public static function initialFor(User $user, Team|int|null $team = null): CrmStage
    {
        return static::defaultsFor($user, $team)->firstWhere('role', 'initial');
    }

    public static function wonFor(User $user, Team|int|null $team = null): CrmStage
    {
        return static::defaultsFor($user, $team)->firstWhere('role', 'won');
    }

    public static function lostFor(User $user, Team|int|null $team = null): CrmStage
    {
        return static::defaultsFor($user, $team)->firstWhere('role', 'lost');
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
    public static function intermediatesFor(User $user, Team|int|null $team = null): Collection
    {
        return static::defaultsFor($user, $team)->filter(fn (CrmStage $stage) => $stage->role === null)->values();
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
