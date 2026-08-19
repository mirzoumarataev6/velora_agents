<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\KnowledgeSource;
use App\Models\User;

class KnowledgeSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, KnowledgeSource $knowledgeSource): bool
    {
        return $user->id === $knowledgeSource->agent->user_id;
    }

    /**
     * Authorized via Gate::authorize('create', [KnowledgeSource::class, $agent]).
     */
    public function create(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function update(User $user, KnowledgeSource $knowledgeSource): bool
    {
        return $user->id === $knowledgeSource->agent->user_id;
    }

    public function delete(User $user, KnowledgeSource $knowledgeSource): bool
    {
        return $user->id === $knowledgeSource->agent->user_id;
    }
}
