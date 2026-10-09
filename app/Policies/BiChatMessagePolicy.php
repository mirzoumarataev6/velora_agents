<?php

namespace App\Policies;

use App\Models\BiChatMessage;
use App\Models\User;

class BiChatMessagePolicy
{
    public function view(User $user, BiChatMessage $message): bool
    {
        return $user->id === $message->user_id;
    }

    public function update(User $user, BiChatMessage $message): bool
    {
        return $user->id === $message->user_id;
    }
}
