<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2d-3-2: team-keyed composite foreign keys, ADDED ALONGSIDE the
 * existing user-keyed ones (`deals_user_workspace_fk`, `deals_user_stage_fk`,
 * `customers_user_workspace_fk`) — none of those three is dropped or
 * changed here.
 *
 * Why these three, now: after Stage 3 a team has several members, so a deal
 * created by member V must be able to point at a workspace/stage created by
 * owner U. The existing composite FKs are keyed on `user_id`, which rejects
 * exactly that cross-member case. The old ones stay until Stage 2d-5 retires
 * `user_id` scoping for good.
 *
 * NOT MYSQL-ONLY, contrary to the SQLite table-rebuild hazard Stage 2d-2's
 * migrations document for ALTERing `crm_workspaces`/`crm_stages` directly
 * (see `2026_10_18_000002`'s docblock). Checked empirically, not assumed on
 * the strength of that precedent: adding these same three composite FKs to
 * `deals`/`customers` via `Schema::table()->foreign()` — which Laravel's
 * SQLite grammar implements the same way, by rebuilding the table (see
 * `SQLiteGrammar::compileForeign()`, "handled on table creation or
 * alteration") — against a fixture with an agent, a workspace, a stage, a
 * customer and a deal, then running both a raw `$user->delete()` and the
 * `DeletesAccount` service, reproduced no `FOREIGN KEY constraint failed`
 * in either case; the existing `AccountDeletionTest`, `DeletesAccountTest`
 * and `TenantIsolationTest` suites all stayed green with these three FKs
 * added. The difference from Stage 2d-2's hazard: that one rebuilt
 * `crm_workspaces`/`crm_stages` — tables on the REFERENCED side of the
 * existing user-keyed FKs — which moved their `sqlite_master` entries
 * relative to `deals`/`customers` and desynchronized the cascade visit
 * order. This migration rebuilds `deals`/`customers` themselves — the
 * REFERENCING side — which does not move `crm_workspaces`/`crm_stages`'s
 * position at all, so the hazard does not apply. Plain `$table->foreign()`
 * is therefore used on every driver, exactly like the pre-existing
 * `deals_user_workspace_fk`/`deals_user_stage_fk`/`customers_user_workspace_fk`.
 */
return new class extends Migration
{
    /**
     * @var list<array{name: string, table: string, columns: list<string>, foreign_table: string, foreign_columns: list<string>}>
     */
    private const FKS = [
        [
            'name' => 'deals_team_workspace_fk',
            'table' => 'deals',
            'columns' => ['team_id', 'crm_workspace_id'],
            'foreign_table' => 'crm_workspaces',
            'foreign_columns' => ['team_id', 'id'],
        ],
        [
            'name' => 'deals_team_stage_fk',
            'table' => 'deals',
            'columns' => ['team_id', 'crm_stage_id'],
            'foreign_table' => 'crm_stages',
            'foreign_columns' => ['team_id', 'id'],
        ],
        [
            'name' => 'customers_team_workspace_fk',
            'table' => 'customers',
            'columns' => ['team_id', 'crm_workspace_id'],
            'foreign_table' => 'crm_workspaces',
            'foreign_columns' => ['team_id', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::FKS as $fk) {
            $this->assertNoMismatch($fk);
        }

        foreach (self::FKS as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk) {
                $table->foreign($fk['columns'], $fk['name'])
                    ->references($fk['foreign_columns'])
                    ->on($fk['foreign_table'])
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // SQLite never stores a foreign key's constraint NAME (see
        // `AccountDeletionGuardsTest`'s and this stage's own schema test
        // for the read-side of the same fact) — `SQLiteGrammar::
        // compileDropForeign()` refuses outright unless given the column
        // array instead, which it then matches structurally. MySQL is the
        // opposite: a bare column array would resolve to the DEFAULT
        // conventional constraint name, not the custom one `up()` gave
        // these three, so it needs the name.
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        foreach (self::FKS as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk, $isSqlite) {
                $isSqlite ? $table->dropForeign($fk['columns']) : $table->dropForeign($fk['name']);
            });
        }
    }

    /**
     * The mismatch this composite FK would otherwise enforce: a row whose
     * own `team_id` differs from the `team_id` of the workspace/stage it
     * points at. A NULL `crm_workspace_id`/`crm_stage_id` can never
     * mismatch — standard SQL never enforces a composite FK when any of its
     * columns is NULL, and the inner join below already excludes those rows
     * the same way.
     */
    private function assertNoMismatch(array $fk): void
    {
        [$ownColumn, $foreignKeyColumn] = $fk['columns'];
        [$foreignTeamColumn, $foreignIdColumn] = $fk['foreign_columns'];

        $mismatches = DB::table($fk['table'])
            ->join(
                $fk['foreign_table'],
                "{$fk['table']}.{$foreignKeyColumn}",
                '=',
                "{$fk['foreign_table']}.{$foreignIdColumn}"
            )
            ->whereColumn("{$fk['table']}.{$ownColumn}", '!=', "{$fk['foreign_table']}.{$foreignTeamColumn}")
            ->count();

        if ($mismatches > 0) {
            throw new RuntimeException(
                "{$fk['name']}: {$mismatches} row(s) in {$fk['table']} have a team_id that ".
                "differs from their referenced {$fk['foreign_table']} row's team_id. ".
                'Resolve the mismatch before running this migration.'
            );
        }
    }
};
