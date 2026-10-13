<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class MessagePolicy
{
    use ChecksTeamPermissions;

    public function view(User $user, Message $message): bool
    {
        return $message->conversation !== null && $this->permitted($user, PermissionCatalog::CONVERSATIONS_VIEW, $message->conversation);
    }

    /**
     * Authorized via Gate::authorize('create', [Message::class, $conversation]).
     */
    public function create(User $user, Conversation $conversation): bool
    {
        return $this->permitted($user, PermissionCatalog::CONVERSATIONS_REPLY, $conversation);
    }
}
