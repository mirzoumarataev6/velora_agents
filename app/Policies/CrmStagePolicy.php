<?php

namespace App\Policies;

use App\Models\CrmStage;
use App\Models\User;

class CrmStagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Rename, recolour, reorder — a stage's role is the one thing this never covers; see CrmStage::booted(). */
    public function update(User $user, CrmStage $stage): bool
    {
        return $user->id === $stage->user_id;
    }

    /** Role-bearing stages (initial/won/lost) are permanent fixtures of the pipeline. */
    public function delete(User $user, CrmStage $stage): bool
    {
        return $user->id === $stage->user_id && $stage->role === null;
    }
}
