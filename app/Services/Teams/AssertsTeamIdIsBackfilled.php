<?php

namespace App\Services\Teams;

use Illuminate\Support\Facades\DB;
use RuntimeException;

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
