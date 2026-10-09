<?php

namespace App\Policies;

use App\Models\BiConnection;
use App\Models\User;

/**
 * Two integers, like every other policy in this module — and unlike
 * `DatabaseConnectionPolicy`, which has to traverse `$connection->agent->user_id`
 * because an agent's connection is owned through the agent.
 *
 * That difference is the whole reorganization in one line: ownership here is a
 * column on the row being read, not a relation that has to be loaded and could
 * be null. A forgotten `where` cannot leak a connection; a forgotten join can.
 */
class BiConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BiConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Dialling the host — deliberately distinct from view(). */
    public function test(User $user, BiConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    public function update(User $user, BiConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    public function delete(User $user, BiConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }
}
