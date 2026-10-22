<?php

namespace App\Policies;

use App\Models\BiConnection;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class BiConnectionPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW);
    }

    public function view(User $user, BiConnection $connection): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $connection);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE);
    }

    public function test(User $user, BiConnection $connection): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $connection);
    }

    public function update(User $user, BiConnection $connection): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $connection);
    }

    public function delete(User $user, BiConnection $connection): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $connection);
    }
}
