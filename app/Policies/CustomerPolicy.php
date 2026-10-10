<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Single-owner product: the only question is whether this row belongs to the
 * signed-in account.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Downloading the directory as CSV — same question as viewAny, named for what it is. */
    public function export(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->id === $customer->user_id;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->id === $customer->user_id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->id === $customer->user_id;
    }
}
