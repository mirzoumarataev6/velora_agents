<?php

namespace App\Policies;

use App\Models\KnowledgeChunk;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Services\Permissions\PermissionCatalog;

class KnowledgeChunkPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_VIEW);
    }

    public function view(User $user, KnowledgeChunk $knowledgeChunk): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_VIEW, $knowledgeChunk);
    }

    public function delete(User $user, KnowledgeChunk $knowledgeChunk): bool
    {
        return $this->permitted($user, PermissionCatalog::AGENTS_UPDATE, $knowledgeChunk);
    }
}
