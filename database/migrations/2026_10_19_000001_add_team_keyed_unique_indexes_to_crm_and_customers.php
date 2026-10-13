<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2d-3-1: team-keyed unique indexes, ADDED ALONGSIDE the existing
 * user-keyed ones on `crm_workspaces` and `customers` — nothing is dropped
 * or changed here, and nothing reads these new indexes yet.
 *
 * Why these four, now: after Stage 3 a team has several members, so a deal
 * created by member V must be able to point at a workspace/stage created by
 * owner U. The existing composite FKs (`deals_user_workspace_fk`, ...) are
 * keyed on `user_id`, which rejects exactly that cross-member case — Stage
 * 2d-3-2 replaces them with team-keyed composite FKs, and a composite FK
 * needs a unique index on its referenced columns, which is what this
 * migration creates first. The old `(user_id, ...)` uniques stay in place
 * until Stage 2d-5 retires `user_id` scoping for good.
 *
 * `customers.phone_key` is nullable (soft-deleted rows null it out — see
 * `Customer::booted()`'s `deleting` hook). A unique index never collides two
 * NULLs under standard SQL NULL-distinctness, on either MySQL or SQLite, so
 * the duplicate pre-flight below excludes NULL rows rather than treating a
 * second NULL as a collision — matching what the index itself will allow.
 *
 * Plain `CREATE UNIQUE INDEX` only, via Laravel's Blueprint — no column type
 * change, no foreign key, so none of the SQLite table-rebuild hazard that
 * Stage 2d-2's migrations document (see `2026_10_18_000002`'s docblock)
 * applies here: SQLite creates a standalone index without rebuilding the
 * table, so this migration needs no driver branch.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, columns: list<string>, name: string, excludeNull: ?string}>
     */
    private const INDEXES = [
        ['table' => 'crm_workspaces', 'columns' => ['team_id', 'id'], 'name' => 'crm_workspaces_team_id_unique', 'excludeNull' => null],
        ['table' => 'crm_workspaces', 'columns' => ['team_id', 'name'], 'name' => 'crm_workspaces_team_name_unique', 'excludeNull' => null],
        ['table' => 'crm_stages', 'columns' => ['team_id', 'id'], 'name' => 'crm_stages_team_id_unique', 'excludeNull' => null],
        ['table' => 'customers', 'columns' => ['team_id', 'phone_key'], 'name' => 'customers_team_phone_key_unique', 'excludeNull' => 'phone_key'],
    ];

    public function up(): void
    {
        // Every table is checked before any index is created — a duplicate
        // found on the fourth table must still leave the first three
        // untouched, so checking and creating are two separate loops.
        foreach (self::INDEXES as $index) {
            $this->assertNoDuplicates($index);
        }

        foreach (self::INDEXES as $index) {
            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->unique($index['columns'], $index['name']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index) {
            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->dropUnique($index['name']);
            });
        }
    }

    private function assertNoDuplicates(array $index): void
    {
        $query = DB::table($index['table'])->select($index['columns']);

        if ($index['excludeNull'] !== null) {
            $query->whereNotNull($index['excludeNull']);
        }

        $duplicateGroups = $query
            ->groupBy($index['columns'])
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicateGroups->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                '%s: %d duplicate group(s) on (%s) — cannot add unique index %s. '.
                'Resolve the duplicates before running this migration.',
                $index['table'],
                $duplicateGroups->count(),
                implode(', ', $index['columns']),
                $index['name'],
            ));
        }
    }
};
