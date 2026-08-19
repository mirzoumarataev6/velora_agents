<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }
}
