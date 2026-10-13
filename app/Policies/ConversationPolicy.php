<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class ConversationPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CONVERSATIONS_VIEW);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->permitted($user, PermissionCatalog::CONVERSATIONS_VIEW, $conversation);
    }

    /**
     * Authorized via Gate::authorize('create', [Conversation::class, $agent]).
     */
    public function create(User $user, Agent $agent): bool
    {
        return $this->permitted($user, PermissionCatalog::CONVERSATIONS_REPLY, $agent);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->permitted($user, PermissionCatalog::CONVERSATIONS_REPLY, $conversation);
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->permitted($user, PermissionCatalog::CONVERSATIONS_TAKEOVER, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->permitted($user, PermissionCatalog::CONVERSATIONS_TAKEOVER, $conversation);
    }
}
