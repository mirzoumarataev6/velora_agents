<?php

namespace App\Policies;

use App\Models\BiSource;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class BiSourcePolicy
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

    public function view(User $user, BiSource $source): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $source);
    }

    public function update(User $user, BiSource $source): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $source);
    }

    public function delete(User $user, BiSource $source): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $source);
    }
}
