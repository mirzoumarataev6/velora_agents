<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\CrmWorkspacePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A private folder for one owner's CRM customers and sales records. */
#[Fillable(['name', 'color'])]
#[UsePolicy(CrmWorkspacePolicy::class)]
class CrmWorkspace extends Model
{
    use SoftDeletes;
    use StampsTeam;

    public const COLORS = ['blue', 'violet', 'green', 'amber', 'rose', 'slate'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * Team-scoped, not user-scoped: Stage 2d-4 makes this (and the other
     * five CRM scopeOwnedBy()s) read `team_id` instead of `user_id`, so a
     * teammate sharing the active team sees the same rows the owner does —
     * the entire point of this stage. Behaviour-neutral for the only case
     * that exists today (one user, one team, current_team_id always valid):
     * `activeTeamId()` resolves to that team's id and this reduces to
     * exactly the same single-team result `user_id` scoping always gave.
     *
     * A null/stale `activeTeamId()` returns nothing, never falling back to
     * `user_id` — a stale `current_team_id` must not leak another team's
     * rows into view just because they happen to share an owner.
     *
     * @param  Builder<CrmWorkspace>  $query
     * @return Builder<CrmWorkspace>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        $teamId = $user->activeTeamId();

        return $teamId !== null
            ? $query->where('crm_workspaces.team_id', $teamId)
            : $query->whereRaw('1 = 0');
    }

    /**
     * The one reserved `__main__` folder a background write files new
     * customers/deals into when there is no request to ask.
     *
     * `$team` is optional and additive: `null` is byte-for-byte today's
     * behaviour (a plain `firstOrCreate` scoped by `user_id` alone, letting
     * StampsTeam resolve `team_id` the way it always has). Passed a team,
     * this still never creates a SECOND `__main__` row for one user —
     * `crm_workspaces` still carries `unique(user_id, name)`, not yet
     * `unique(user_id, team_id, name)` (Stage 2d-5a) — so a user whose
     * existing `__main__` already belongs to a different team has no
     * second slot to be given here; that stays unsupported on purpose
     * rather than worked around.
     *
     * Three outcomes, in order:
     * 1. A `__main__` row already stamped with this exact team — return it.
     * 2. A legacy `__main__` row with `team_id` still NULL (created before
     *    this method existed, or by the `null`-team path above) — adopt
     *    it in place, one row, no new insert.
     * 3. Neither exists — create one, with `team_id` set explicitly so
     *    StampsTeam's own `user_id` fallback is never consulted.
     */
    public static function defaultFor(User $user, Team|int|null $team = null): self
    {
        if ($team === null) {
            return $user->crmWorkspaces()->firstOrCreate(
                ['name' => '__main__'],
                ['color' => 'blue', 'is_default' => true],
            );
        }

        $teamId = $team instanceof Team ? $team->id : $team;

        $owned = $user->crmWorkspaces()->where('name', '__main__')->where('team_id', $teamId)->first();

        if ($owned !== null) {
            return $owned;
        }

        $legacy = $user->crmWorkspaces()->where('name', '__main__')->whereNull('team_id')->first();

        if ($legacy !== null) {
            $legacy->forceFill(['team_id' => $teamId])->save();

            return $legacy;
        }

        $workspace = new self([
            'name' => '__main__',
            'color' => 'blue',
            'is_default' => true,
        ]);
        $workspace->user_id = $user->id;
        $workspace->team_id = $teamId;
        $workspace->save();

        return $workspace;
    }

    public function displayName(): string
    {
        return $this->is_default ? __('crm.workspaces.default_name') : $this->name;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'crm_workspace_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'crm_workspace_id');
    }
}
