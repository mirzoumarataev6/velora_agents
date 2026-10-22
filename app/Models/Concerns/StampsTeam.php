<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait StampsTeam
{
    private static int $teamStampingSuspended = 0;

    protected static function bootStampsTeam(): void
    {
        static::creating(function (Model $model) {
            if (static::$teamStampingSuspended > 0) {
                return;
            }

            if ($model->team_id !== null) {
                return;
            }

            $model->team_id = static::resolveTeamIdFor($model);
        });
    }

    public static function withoutTeamStamping(callable $callback): mixed
    {
        static::$teamStampingSuspended++;

        try {
            return $callback();
        } finally {
            static::$teamStampingSuspended--;
        }
    }

    private static function resolveTeamIdFor(Model $model): ?int
    {
        $agentId = $model->agent_id ?? null;

        if ($agentId !== null) {
            return DB::table('agents')->where('id', $agentId)->value('team_id');
        }

        $userId = $model->user_id ?? null;

        if ($userId !== null) {
            return DB::table('users')->where('id', $userId)->value('current_team_id');
        }

        return null;
    }
}
