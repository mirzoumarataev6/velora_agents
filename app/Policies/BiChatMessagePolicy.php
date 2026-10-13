<?php

namespace App\Policies;

use App\Models\BiChatMessage;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class BiChatMessagePolicy
{
    use ChecksTeamPermissions;

    public function view(User $user, BiChatMessage $message): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $message);
    }

    public function update(User $user, BiChatMessage $message): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $message);
    }
}
