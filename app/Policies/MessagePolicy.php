<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function view(User $user, Message $message): bool
    {
        return $user->id === $message->conversation->agent->user_id;
    }

    /**
     * Authorized via Gate::authorize('create', [Message::class, $conversation]).
     */
    public function create(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->agent->user_id;
    }
}
