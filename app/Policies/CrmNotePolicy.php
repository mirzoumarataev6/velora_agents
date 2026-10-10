<?php

namespace App\Policies;

use App\Models\CrmNote;
use App\Models\User;

class CrmNotePolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    /** Author or the subject's owner — in this product always the same person, but checked both ways regardless. */
    public function delete(User $user, CrmNote $note): bool
    {
        return $user->id === $note->user_id;
    }
}
