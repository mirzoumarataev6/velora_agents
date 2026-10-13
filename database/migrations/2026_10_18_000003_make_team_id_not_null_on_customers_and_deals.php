<?php

use App\Services\Teams\AssertsTeamIdIsBackfilled;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2d-2, group (c): `customers` and `deals` — the two tables that
 * hold the composite foreign keys against `crm_workspaces`/`crm_stages`
 * (`customers_user_workspace_fk` in
 * `2026_10_06_000004_add_crm_workspace_to_customers.php`;
 * `deals_user_workspace_fk` in `2026_10_06_000003_create_crm_workspaces_table.php`;
 * `deals_user_stage_fk` in `2026_10_14_000001_create_crm_stages_table.php`).
 *
 * MySQL: `customers`/`deals` only, via a raw `ALTER TABLE ... MODIFY` —
 * `crm_workspaces`/`crm_stages` were already completed in full by group
 * (b)'s migration there, since MySQL's in-place ALTER never needed the
 * SQLite-only deferral that migration documents.
 *
 * SQLite: all FOUR of `crm_workspaces`, `crm_stages`, `customers`,
 * `deals` are flipped together, HERE, in this one file — finishing what
 * group (b) deliberately left undone on this driver. Verified
 * empirically, not assumed: against a scratch SQLite database seeded
 * with one row per StampsTeam table, flipping all four together in the
 * order crm_workspaces, crm_stages, deals, customers, then deleting the
 * owning user (cascading through every one of them), raised no foreign
 * key error — repeated runs included. Reversing that order (deals/
 * customers before crm_workspaces/crm_stages) reproduces the same
 * failure group (b)'s docblock describes.
 */
return new class extends Migration
{
    use AssertsTeamIdIsBackfilled;

    private const MYSQL_TABLES = ['customers', 'deals'];

    private const SQLITE_TABLES = ['crm_workspaces', 'crm_stages', 'customers', 'deals'];

    public function up(): void
    {
        if ($this->isSqlite()) {
            foreach (self::SQLITE_TABLES as $table) {
                $this->assertTeamIdBackfilled($table);
            }

            foreach (self::SQLITE_TABLES as $table) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('team_id')->nullable(false)->change();
                });
            }

            return;
        }

        foreach (self::MYSQL_TABLES as $table) {
            $this->assertTeamIdBackfilled($table);
        }

        foreach (self::MYSQL_TABLES as $table) {
            DB::statement("alter table {$table} modify team_id bigint unsigned not null");
        }
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            foreach (array_reverse(self::SQLITE_TABLES) as $table) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('team_id')->nullable()->change();
                });
            }

            return;
        }

        foreach (self::MYSQL_TABLES as $table) {
            DB::statement("alter table {$table} modify team_id bigint unsigned null");
        }
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
