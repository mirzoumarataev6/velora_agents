<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Channel $channel): bool
    {
        return $user->id === $channel->agent->user_id;
    }

    /**
     * Authorized via Gate::authorize('create', [Channel::class, $agent]).
     */
    public function create(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function update(User $user, Channel $channel): bool
    {
        return $user->id === $channel->agent->user_id;
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $user->id === $channel->agent->user_id;
    }
}
