<?php

namespace App\Services\Permissions;

use App\Models\Role;
use App\Models\User;

final class AuthorizesRoleGrants
{
    public function canGrant(User $actor, Role $role): bool
    {
        if ($role->team_id === null || $actor->membershipIn($role->team_id) === null) {
            return false;
        }

        if ($actor->isOwnerOf($role->team_id)) {
            return true;
        }

        return ! $role->is_owner && $this->canGrantPermissions($actor, $role->team_id, $role->permissionKeys());
    }

    public function canGrantPermissions(User $actor, int $teamId, array $permissions): bool
    {
        // Role management must not become a way to mint permissions the
        // manager lacks, including by editing their own role or inviting
        // another account with a more powerful existing role.
        foreach ($permissions as $permission) {
            if (! $actor->hasPermissionOn($teamId, $permission)) {
                return false;
            }
        }

        return true;
    }
}
