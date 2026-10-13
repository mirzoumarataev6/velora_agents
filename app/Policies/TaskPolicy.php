<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class TaskPolicy
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

    public function view(User $user, Task $task): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_VIEW, $task);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->permitted($user, PermissionCatalog::ANALYTICS_MANAGE, $task);
    }
}
