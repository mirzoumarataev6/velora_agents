<?php

namespace App\Policies;

use App\Models\CrmStage;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class CrmStagePolicy
{
    use ChecksTeamPermissions;

    public function view(User $user, CrmStage $stage): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW, $stage);
    }

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE);
    }

    public function update(User $user, CrmStage $stage): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $stage);
    }

    public function delete(User $user, CrmStage $stage): bool
    {
        return $stage->role === null && $this->permitted($user, PermissionCatalog::CRM_MANAGE, $stage);
    }
}
