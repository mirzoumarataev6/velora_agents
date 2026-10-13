<?php

namespace App\Policies;

use App\Models\BiColumn;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class BiColumnPolicy
{
    use ChecksTeamPermissions;

    public function view(User $user, BiColumn $column): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $column);
    }

    public function update(User $user, BiColumn $column): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $column);
    }
}
