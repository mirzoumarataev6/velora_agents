<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ScopesActiveTeam
{
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        // A stale navigation pointer must yield no rows, including rows the
        // actor originally created in a team they have since left.
        $teamId = $user->activeTeamId();

        return $teamId === null
            ? $query->whereRaw('1 = 0')
            : $query->where($query->getModel()->qualifyColumn('team_id'), $teamId);
    }
}
