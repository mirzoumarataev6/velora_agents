<?php

namespace App\Policies;

use App\Models\BiDataset;
use App\Models\User;

/**
 * The gate the whole query layer leans on.
 *
 * A compiled analytical SELECT names the table belonging to a BiDataset it was
 * handed, so "may this account read this dataset" is the ONLY question standing
 * between one owner's dashboard and another owner's rows. It is answered by
 * comparing two integers and nothing else — no role, no share, no nullable
 * relation to traverse — because every extra clause here is a way to be wrong.
 */
class BiDatasetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BiDataset $dataset): bool
    {
        return $user->id === $dataset->user_id;
    }

    /** Running a query against it. Deliberately distinct from view(). */
    public function query(User $user, BiDataset $dataset): bool
    {
        return $user->id === $dataset->user_id;
    }

    public function update(User $user, BiDataset $dataset): bool
    {
        return $user->id === $dataset->user_id;
    }

    public function delete(User $user, BiDataset $dataset): bool
    {
        return $user->id === $dataset->user_id;
    }
}
