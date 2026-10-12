<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Denormalizes `team_id` onto a tenant row at creation time, the same column
 * Stage 1's `team:migrate-users` backfilled onto existing rows — so a row
 * born today already carries what the backfill had to go back and fill in
 * for old ones. Nothing reads `team_id` for authorization yet; this is
 * purely keeping the column from falling behind again.
 *
 * Resolution order mirrors `team:migrate-users`' own two table groups:
 * prefer the row's own `agent_id` (one hop to `agents.team_id`) over its
 * `user_id` (one hop to `users.current_team_id`) — a row with both (`deals`,
 * `usage_logs`) is still agent-scoped first. Plain `DB::table()` lookups
 * rather than loading the related Eloquent model: this runs on every single
 * creating event, and a model hydration is unnecessary cost for one column.
 *
 * Two invariants, deliberately absolute:
 * - Never overwrites a `team_id` already set — a test or a future write
 *   path that sets it explicitly always wins.
 * - Never throws. An unresolvable row (an agent or user without a team yet)
 *   is left with `team_id = NULL`; `team:verify-migration` is the
 *   permanent safety net that notices, not this hook.
 */
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

    /**
     * Lets a caller create a row that deliberately skips this stamping —
     * the pre-Stage-2a "owner already has a team, but this row still
     * arrived with no team_id" gap `team:migrate-users`' top-up pass exists
     * to repair. Real callers never need this (every write path now stamps
     * on its own); it exists for the tests that simulate that gap without
     * also silencing this model's other creation-time behaviour (an
     * Observer's audit row, say) the way `Model::withoutEvents()` would.
     * Counter-based, so nested calls (or calls on more than one of these
     * models at once) compose safely.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
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
