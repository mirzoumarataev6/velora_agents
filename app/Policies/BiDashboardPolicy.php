<?php

namespace App\Policies;

use App\Models\BiDashboard;
use App\Models\User;

/**
 * Two integers, like every other policy in this module. See BiDatasetPolicy for
 * why there is deliberately nothing else in here.
 */
class BiDashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BiDashboard $dashboard): bool
    {
        return $user->id === $dashboard->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BiDashboard $dashboard): bool
    {
        return $user->id === $dashboard->user_id;
    }

    public function delete(User $user, BiDashboard $dashboard): bool
    {
        return $user->id === $dashboard->user_id;
    }
}
