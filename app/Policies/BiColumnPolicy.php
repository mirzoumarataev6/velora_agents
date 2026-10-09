<?php

namespace App\Policies;

use App\Models\BiColumn;
use App\Models\User;

/**
 * Columns carry their own `user_id` rather than reaching through the dataset, so
 * the check is one comparison and needs no eager load — the schema editor
 * authorizes every column of a wide table on one page load.
 */
class BiColumnPolicy
{
    public function view(User $user, BiColumn $column): bool
    {
        return $user->id === $column->user_id;
    }

    public function update(User $user, BiColumn $column): bool
    {
        return $user->id === $column->user_id;
    }
}
