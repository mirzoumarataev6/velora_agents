<?php

namespace App\Policies;

use App\Models\CrmWorkspace;
use App\Models\User;

class CrmWorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CrmWorkspace $workspace): bool
    {
        return $user->id === $workspace->user_id;
    }

    public function update(User $user, CrmWorkspace $workspace): bool
    {
        return $user->id === $workspace->user_id && ! $workspace->is_default;
    }

    public function delete(User $user, CrmWorkspace $workspace): bool
    {
        return $user->id === $workspace->user_id && ! $workspace->is_default;
    }
}
