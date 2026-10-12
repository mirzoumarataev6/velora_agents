<?php

namespace App\Services\Teams;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The pre-flight check every Stage 2d-2 NOT NULL migration runs, once per
 * table, immediately before that table's own `Schema::table()` call — not
 * once for the whole migration file, so a group of several tables fails
 * exactly as far as it got: a table whose data wasn't clean is never
 * touched, but a table already altered earlier in the same `up()` stays
 * altered. Re-running the migration after fixing the backfill simply
 * reapplies `nullable(false)` to those already-converted columns (a
 * no-op) and proceeds from where it stopped.
 *
 * `team:verify-migration` is the existing, unmodified tool this reuses the
 * same check from (`VerifyTeamMigrationCommand::handle()`) — this guard
 * does not replace it, it just means a migration cannot be run against
 * data that command would have refused.
 */
trait AssertsTeamIdIsBackfilled
{
    protected function assertTeamIdBackfilled(string $table): void
    {
        $missing = DB::table($table)->whereNull('team_id')->count();

        if ($missing > 0) {
            throw new RuntimeException(
                "{$table}: {$missing} row(s) still have a NULL team_id. Run ".
                '`php artisan team:migrate-users` then `php artisan team:verify-migration` '.
                'until it reports clean before running this migration.'
            );
        }
    }
}
