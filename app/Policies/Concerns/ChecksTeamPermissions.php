<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

trait ChecksTeamPermissions
{
    protected function permitted(User $user, string $permission, ?Model $subject = null): bool
    {
        return Gate::forUser($user)->allows($permission, $subject === null ? [] : [$subject]);
    }
}
