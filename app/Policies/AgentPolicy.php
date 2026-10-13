<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class AgentPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_VIEW);
    }

    public function view(User $user, Agent $agent): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_VIEW, $agent);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_CREATE);
    }

    public function update(User $user, Agent $agent): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_UPDATE, $agent);
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_DELETE, $agent);
    }
}
