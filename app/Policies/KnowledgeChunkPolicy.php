<?php

namespace App\Policies;

use App\Models\KnowledgeChunk;
use App\Models\User;

class KnowledgeChunkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, KnowledgeChunk $knowledgeChunk): bool
    {
        return $user->id === $knowledgeChunk->agent->user_id;
    }

    public function delete(User $user, KnowledgeChunk $knowledgeChunk): bool
    {
        return $user->id === $knowledgeChunk->agent->user_id;
    }
}
