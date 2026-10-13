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
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE);
    }

    /** Downloading the board as CSV — same question as viewAny, named for what it is. */
    public function export(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW);
    }

    public function view(User $user, Deal $deal): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $deal);
    }

    public function update(User $user, Deal $deal): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $deal);
    }

    public function delete(User $user, Deal $deal): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $deal);
    }
}
