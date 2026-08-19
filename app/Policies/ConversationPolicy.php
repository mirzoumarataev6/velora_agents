<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->agent->user_id;
    }

    /**
     * Authorized via Gate::authorize('create', [Conversation::class, $agent]).
     */
    public function create(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->agent->user_id;
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->agent->user_id;
    }
}
