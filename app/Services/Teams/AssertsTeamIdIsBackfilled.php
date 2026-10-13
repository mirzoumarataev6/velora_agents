<?php

namespace App\Services\Teams;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The pre-flight check every Stage 2d-2 NOT NULL migration runs against
 * every table in its group, BEFORE any of them is altered — each
 * migration guards its whole group in one loop first, then alters in a
 * separate loop, rather than interleaving a check with that same table's
 * own `Schema::table()` call.
 *
 * That ordering is the actual protection, not a nicety: MySQL's DDL is
 * not transactional — each `ALTER TABLE` commits on its own, immediately
 * — so interleaving would let an early table in the group get altered
 * before a later one's bad data is ever noticed. Guarding the whole
 * group first means a single unbackfilled row anywhere in it stops the
 * migration before a single `ALTER TABLE` in that group has run.
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
