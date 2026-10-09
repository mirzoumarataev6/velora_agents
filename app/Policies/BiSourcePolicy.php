<?php

namespace App\Policies;

use App\Models\BiSource;
use App\Models\User;

/**
 * Single-owner product: the only question is whether this row belongs to the
 * signed-in account. Same shape as CustomerPolicy and for the same reason.
 */
class BiSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, BiSource $source): bool
    {
        return $user->id === $source->user_id;
    }

    public function update(User $user, BiSource $source): bool
    {
        return $user->id === $source->user_id;
    }

    public function delete(User $user, BiSource $source): bool
    {
        return $user->id === $source->user_id;
    }
}
