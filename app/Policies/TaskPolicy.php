<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Projects\ProjectAccess;

class TaskPolicy
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

    public function view(User $user, Task $task): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_VIEW, $task)
            && $this->visibleFromCrm($user, $task);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $task)
            && $this->visibleFromCrm($user, $task, true);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->permitted($user, PermissionCatalog::CRM_MANAGE, $task)
            && $this->visibleFromCrm($user, $task, true);
    }

    private function visibleFromCrm(User $user, Task $task, bool $write = false): bool
    {
        // The permission matrix checks unsaved models; persisted tasks always
        // have a board, which is where private-board access is enforced.
        if (! $task->exists) {
            return true;
        }

        $board = $task->projectBoard;
        if ($board === null || $board->archived_at !== null || $task->archived_at !== null) {
            return false;
        }

        if ($board->slug === '_inbox' && $board->visibility === 'team') {
            return true;
        }

        $access = app(ProjectAccess::class);

        return $access->canView($user, $board)
            && (! $write || $user->can(PermissionCatalog::PROJECTS_EDIT, $board));
    }
}
