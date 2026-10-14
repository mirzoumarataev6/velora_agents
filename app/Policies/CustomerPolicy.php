<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class CustomerPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE);
    }

    /** Downloading the directory as CSV — same question as viewAny, named for what it is. */
    public function export(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW, $customer);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $customer);
    }
}
