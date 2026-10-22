<?php

namespace App\Services\Permissions;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class TeamResolver
{
    public function resolveTeamId(Model $model): ?int
    {
        try {
            $teamId = $model->getAttribute('team_id');
        } catch (Throwable) {
            return null;
        }

        return is_numeric($teamId) ? (int) $teamId : null;
    }

    public function resolveTeam(Model $model): ?Team
    {
        $teamId = $this->resolveTeamId($model);

        return $teamId === null ? null : Team::find($teamId);
    }
}
