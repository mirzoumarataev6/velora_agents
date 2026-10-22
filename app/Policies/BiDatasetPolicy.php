<?php

namespace App\Policies;

use App\Models\BiDataset;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class BiDatasetPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW);
    }

    public function view(User $user, BiDataset $dataset): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $dataset);
    }

    public function query(User $user, BiDataset $dataset): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $dataset);
    }

    public function update(User $user, BiDataset $dataset): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $dataset);
    }

    public function delete(User $user, BiDataset $dataset): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $dataset);
    }
}
