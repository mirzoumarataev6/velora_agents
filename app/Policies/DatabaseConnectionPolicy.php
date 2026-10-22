<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class DatabaseConnectionPolicy
{
    use ChecksTeamPermissions;

    public function view(User $user, DatabaseConnection $connection): bool
    {
        return $this->permitted($user, PermissionCatalog::CATALOG_VIEW, $connection);
    }

    public function create(User $user, Agent $agent): bool
    {
        return $this->permitted($user, PermissionCatalog::CATALOG_MANAGE, $agent);
    }

    public function update(User $user, DatabaseConnection $connection): bool
    {
        return $this->permitted($user, PermissionCatalog::CATALOG_MANAGE, $connection);
    }

    public function delete(User $user, DatabaseConnection $connection): bool
    {
        return $this->permitted($user, PermissionCatalog::CATALOG_MANAGE, $connection);
    }
}
