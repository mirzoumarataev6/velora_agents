<?php

namespace App\Policies;

use App\Models\BiWidget;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class BiWidgetPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW);
    }

    public function view(User $user, BiWidget $widget): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $widget);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE);
    }

    public function update(User $user, BiWidget $widget): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $widget);
    }

    public function delete(User $user, BiWidget $widget): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $widget);
    }
}
