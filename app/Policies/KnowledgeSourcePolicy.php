<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class KnowledgeSourcePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_VIEW);
    }

    public function view(User $user, KnowledgeSource $knowledgeSource): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_VIEW, $knowledgeSource);
    }

    public function create(User $user, Agent $agent): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_UPDATE, $agent);
    }

    public function update(User $user, KnowledgeSource $knowledgeSource): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_UPDATE, $knowledgeSource);
    }

    public function delete(User $user, KnowledgeSource $knowledgeSource): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_UPDATE, $knowledgeSource);
    }
}
