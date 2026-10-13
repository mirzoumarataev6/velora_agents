<?php

namespace App\Policies;

use App\Models\BiDashboard;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class BiDashboardPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW);
    }

    public function view(User $user, BiDashboard $dashboard): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $dashboard);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE);
    }

    public function update(User $user, BiDashboard $dashboard): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $dashboard);
    }

    public function delete(User $user, BiDashboard $dashboard): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $dashboard);
    }
}
