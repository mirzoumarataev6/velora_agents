<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class DealPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE);
    }

    public function export(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW);
    }

    public function view(User $user, Deal $deal): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW, $deal);
    }

    public function update(User $user, Deal $deal): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $deal);
    }

    public function delete(User $user, Deal $deal): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $deal);
    }
}
