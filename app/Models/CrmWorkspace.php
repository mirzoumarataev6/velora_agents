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
     * A shared team's default belongs to the team, regardless of who first
     * opened CRM. firstOrCreate also retries a concurrent insert against the
     * team/name unique key. The omitted-team branch remains for legacy callers.
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

        return static::unguarded(fn () => static::firstOrCreate(
            ['team_id' => $teamId, 'name' => '__main__'],
            ['user_id' => $user->id, 'color' => 'blue', 'is_default' => true],
        ));
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
