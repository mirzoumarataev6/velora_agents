<?php

namespace App\Services\Permissions;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Reads the team a tenant row belongs to off its own `team_id` column —
 * nothing else. No join through `agent_id`/`user_id`: `StampsTeam` is what
 * keeps `team_id` denormalized onto the row in the first place (see its
 * docblock), so a model that still has NULL there has not been backfilled
 * or stamped yet, and this resolver reports that honestly as "no team"
 * rather than reaching past the column to guess one.
 *
 * Deliberately tolerant: a model with no `team_id` column at all (anything
 * outside the 26 tenant tables), or one that throws reading an attribute,
 * resolves to null rather than raising. This is read on request paths that
 * must not be the thing that turns a bug into a 500.
 */
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
