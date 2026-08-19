<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->id === $lead->agent->user_id;
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->id === $lead->agent->user_id;
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->id === $lead->agent->user_id;
    }
}
