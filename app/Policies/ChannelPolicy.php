<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class ChannelPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::CHANNELS_VIEW);
    }

    public function view(User $user, Channel $channel): bool
    {
        return $this->permitted($user, PermissionCatalog::CHANNELS_VIEW, $channel);
    }

    /**
     * Authorized via Gate::authorize('create', [Channel::class, $agent]).
     */
    public function create(User $user, Agent $agent): bool
    {
        return $this->permitted($user, PermissionCatalog::CHANNELS_MANAGE, $agent);
    }

    public function update(User $user, Channel $channel): bool
    {
        return $this->permitted($user, PermissionCatalog::CHANNELS_MANAGE, $channel);
    }

    public function delete(User $user, Channel $channel): bool
    {
        return $this->permitted($user, PermissionCatalog::CHANNELS_MANAGE, $channel);
    }
}
