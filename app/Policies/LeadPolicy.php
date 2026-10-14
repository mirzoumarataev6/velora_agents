<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class LeadPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW);
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW, $lead);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $lead);
    }
}
