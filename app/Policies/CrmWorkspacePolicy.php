<?php

namespace App\Policies;

use App\Models\CrmWorkspace;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class CrmWorkspacePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE);
    }

    public function view(User $user, CrmWorkspace $workspace): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $workspace);
    }

    public function update(User $user, CrmWorkspace $workspace): bool
    {
        return ! $workspace->is_default && $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $workspace);
    }

    public function delete(User $user, CrmWorkspace $workspace): bool
    {
        return ! $workspace->is_default && $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $workspace);
    }
}
