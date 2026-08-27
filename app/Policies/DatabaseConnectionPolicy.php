<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Models\User;

/**
 * A live connection carries the customer's own database credentials, so every
 * gate here is the same question: does this user own the agent the connection
 * hangs off? There is no sharing, no read-only role, and no admin bypass.
 */
class DatabaseConnectionPolicy
{
    public function view(User $user, DatabaseConnection $connection): bool
    {
        return $user->id === $connection->agent->user_id;
    }

    /**
     * Authorized via Gate::authorize('create', [DatabaseConnection::class, $agent]).
     */
    public function create(User $user, Agent $agent): bool
    {
        return $user->id === $agent->user_id;
    }

    public function update(User $user, DatabaseConnection $connection): bool
    {
        return $user->id === $connection->agent->user_id;
    }

    public function delete(User $user, DatabaseConnection $connection): bool
    {
        return $user->id === $connection->agent->user_id;
    }
}
