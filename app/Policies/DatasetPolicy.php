<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class DatasetPolicy
{
    use ChecksTeamPermissions;

    public function view(User $user, Dataset $dataset): bool
    {
        return $this->permitted($user, PermissionCatalog::CATALOG_VIEW, $dataset);
    }

    /**
     * Authorized via Gate::authorize('create', [Dataset::class, $agent]).
     */
    public function create(User $user, Agent $agent): bool
    {
        return $this->permitted($user, PermissionCatalog::CATALOG_MANAGE, $agent);
    }

    public function update(User $user, Dataset $dataset): bool
    {
        return $this->permitted($user, PermissionCatalog::CATALOG_MANAGE, $dataset);
    }

    public function delete(User $user, Dataset $dataset): bool
    {
        return $this->permitted($user, PermissionCatalog::CATALOG_MANAGE, $dataset);
    }
}
