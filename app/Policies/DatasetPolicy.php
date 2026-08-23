<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\User;

class DatasetPolicy
{
    public function view(User $user, Dataset $dataset): bool
    {
        return $user->id === $dataset->agent->user_id;
    }

    /**
     * Authorized via Gate::authorize('create', [Dataset::class, $agent]).
     */
    public function create(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function update(User $user, Dataset $dataset): bool
    {
        return $user->id === $dataset->agent->user_id;
    }

    public function delete(User $user, Dataset $dataset): bool
    {
        return $user->id === $dataset->agent->user_id;
    }
}
