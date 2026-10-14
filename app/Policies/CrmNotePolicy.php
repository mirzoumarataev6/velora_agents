<?php

namespace App\Policies;

use App\Models\CrmNote;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class CrmNotePolicy
{
    use ChecksTeamPermissions;

    public function view(User $user, CrmNote $note): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW, $note);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE);
    }

    /** Notes are shared CRM data; deletion requires the team's manage permission. */
    public function delete(User $user, CrmNote $note): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $note);
    }
}
