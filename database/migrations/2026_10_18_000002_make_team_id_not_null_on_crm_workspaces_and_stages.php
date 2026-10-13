<?php

use App\Services\Teams\AssertsTeamIdIsBackfilled;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2d-2, group (b): `crm_workspaces` and `crm_stages`.
 *
 * MYSQL-ONLY NOT NULL IN THIS FILE. SQLite is deliberately left nullable
 * here and finished by group (c) (`2026_10_18_000003_...`) instead — this
 * is the documented exception item 3 of the task allows, used because
 * this specific group genuinely reproduces the cascading-delete failure
 * on SQLite, verified empirically, not assumed:
 *
 * Deleting a user who owns a deal, a customer, a workspace and a stage,
 * against a scratch SQLite database where ONLY `crm_workspaces`/
 * `crm_stages` had been rebuilt (by an earlier version of this same
 * migration using `Schema::table()->change()`) while `deals`/`customers`
 * still carried their original (`2026_10_17_000007`) un-rebuilt shape,
 * raised `FOREIGN KEY constraint failed` on the `DELETE FROM users`
 * itself — reproducing `tests/Feature/Settings/AccountDeletionTest.php`'s
 * own cascade test failing the same way. Rebuilding crm_workspaces/
 * crm_stages ALONE is enough to desynchronize SQLite's FK visit order
 * relative to deals/customers, which still reference them by composite
 * FK — it does not require deals/customers to be rebuilt too; the mere
 * act of altering crm_workspaces/crm_stages on their own is what trips
 * it. Flipping all four together, in one pass, in the order
 * crm_workspaces/crm_stages then deals/customers — which is what group
 * (c)'s migration does for SQLite — was verified safe (see that file).
 *
 * MySQL's `ALTER TABLE ... MODIFY` is in-place; it never rebuilds the
 * table or touches any other table's foreign keys, so none of this
 * applies there — group (b) completes in full, on MySQL, in this file.
 */
return new class extends Migration
{
    use AssertsTeamIdIsBackfilled;

    private const TABLES = ['crm_workspaces', 'crm_stages'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTeamIdBackfilled($table);
        }

        if ($this->isSqlite()) {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("alter table {$table} modify team_id bigint unsigned not null");
        }
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("alter table {$table} modify team_id bigint unsigned null");
        }
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
