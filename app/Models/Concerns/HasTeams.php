<?php

namespace App\Models\Concerns;

use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

trait HasTeams
{
    private ?array $teamMembershipCache = null;

    private array $teamPermissionSetCache = [];

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot(['role_id', 'joined_at'])
            ->withTimestamps();
    }

    public function cachedTeamMemberships(): Collection
    {
        if ($this->teamMembershipCache === null) {

            $memberships = TeamMembership::query()
                ->join('roles', function ($join) {
                    $join->on('roles.id', '=', 'team_user.role_id')
                        ->on('roles.team_id', '=', 'team_user.team_id');
                })
                ->join('teams', 'teams.id', '=', 'team_user.team_id')
                ->where('team_user.user_id', $this->id)
                ->orderBy('team_user.joined_at')
                ->select('team_user.*', 'teams.name as team_name', 'teams.is_personal as team_is_personal', 'roles.name as role_name', 'roles.slug as role_slug', 'roles.is_owner as role_is_owner')
                ->get();

            foreach ($memberships as $membership) {
                $membership->setRelation('role', (new Role)->newFromBuilder([
                    'id' => $membership->role_id,
                    'team_id' => $membership->team_id,
                    'name' => $membership->role_name,
                    'slug' => $membership->role_slug,
                    'is_owner' => $membership->role_is_owner,
                ]));
            }

            $this->teamMembershipCache = $memberships->keyBy('team_id')->all();
        }

        return collect($this->teamMembershipCache);
    }

    public function membershipIn(Team|int $team): ?TeamMembership
    {
        return $this->cachedTeamMemberships()->get($this->teamIdOf($team));
    }

    public function isOwnerOf(Team|int $team): bool
    {
        return (bool) $this->membershipIn($team)?->role?->is_owner;
    }

    public function hasPermissionOn(Team|int|null $team, string $permission): bool
    {
        PermissionCatalog::assertValid($permission);

        if ($team === null) {
            return false;
        }

        $membership = $this->membershipIn($team);

        if ($membership === null) {
            return false;
        }

        if ($membership->role?->is_owner) {
            return true;
        }

        return in_array($permission, $this->permissionSetFor($team, $membership->role), true);
    }

    public function flushPermissionCache(): void
    {
        $this->teamMembershipCache = null;
        $this->teamPermissionSetCache = [];
    }

    private function permissionSetFor(Team|int $team, ?Role $role): array
    {
        $teamId = $this->teamIdOf($team);

        if (array_key_exists($teamId, $this->teamPermissionSetCache)) {
            return $this->teamPermissionSetCache[$teamId];
        }

        return $this->teamPermissionSetCache[$teamId] = $role?->permissionKeys() ?? [];
    }

    private function teamIdOf(Team|int $team): int
    {
        return $team instanceof Team ? $team->id : $team;
    }
}
