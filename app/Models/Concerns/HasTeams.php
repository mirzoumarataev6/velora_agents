<?php

namespace App\Models\Concerns;

use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The RBAC read side: "is this user a member of this team, with what role,
 * and does that role carry this permission." Nothing here is wired into
 * authorization yet (no Policy, no Gate::before) — this stage only builds
 * the backbone a later one reads from.
 *
 * Memoized per User instance, not per request: `flushPermissionCache()` is
 * the seam a later stage's Policy would call after a role/membership
 * change mid-request (switching teams, an admin editing their own
 * permissions), and it is what the tests use to prove the cache does not
 * paper over a stale answer.
 *
 * Membership (and its role) is fetched in one query per team the first
 * time it's asked about, and never again for that instance — `isOwnerOf()`
 * and `hasPermissionOn()` both read the same cached membership, so asking
 * both about the same team costs nothing extra. `hasPermissionOn()` adds
 * at most one more query, for the role's permission bundle, also cached
 * per team — an Owner never pays that cost at all, since Owner short-
 * circuits to true without reading the permission set.
 */
trait HasTeams
{
    /** @var array<int, ?TeamMembership>|null team_id => this user's membership on it (or null, meaning "checked, not a member") */
    private ?array $teamMembershipCache = null;

    /** @var array<int, list<string>> team_id => that team's role's permission keys, fetched once */
    private array $teamPermissionSetCache = [];

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot(['role_id', 'joined_at'])
            ->withTimestamps();
    }

    public function membershipIn(Team|int $team): ?TeamMembership
    {
        $teamId = $this->teamIdOf($team);

        $this->teamMembershipCache ??= [];

        if (array_key_exists($teamId, $this->teamMembershipCache)) {
            return $this->teamMembershipCache[$teamId];
        }

        $membership = TeamMembership::with('role')
            ->where('team_id', $teamId)
            ->where('user_id', $this->id)
            ->first();

        return $this->teamMembershipCache[$teamId] = $membership;
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

    /** Clears both caches — call after a role/permission/membership change this instance's answers must reflect. */
    public function flushPermissionCache(): void
    {
        $this->teamMembershipCache = null;
        $this->teamPermissionSetCache = [];
    }

    /** @return list<string> */
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
