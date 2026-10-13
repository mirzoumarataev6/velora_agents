<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2d-5a: retires the two OLD user-keyed uniques this product's
 * single-user-per-account history left behind — `crm_workspaces`'
 * `unique(user_id, name)` and `customers`' `unique(user_id, phone_key)` —
 * now that their team-keyed replacements (`crm_workspaces_team_name_unique`,
 * `customers_team_phone_key_unique`, both from Stage 2d-3-1) exist and are
 * what every read/write path (Stage 2d-4) actually uses. Until this
 * migration, one owner's SECOND team could never get its own `__main__`
 * workspace or re-capture a phone number its first team already held — see
 * `TeamAwareCrmDefaultsTest`'s now-removed TEMPORARY test for exactly that
 * limitation, pinned there until this ran.
 *
 * Scope is deliberately narrow: only these two indexes. `crm_workspaces`'
 * OTHER user-keyed unique (`crm_workspaces_owner_id_unique`, on
 * `(user_id, id)`) stays — the user-keyed composite FKs
 * (`deals_user_workspace_fk`, `deals_user_stage_fk`,
 * `customers_user_workspace_fk`) reference it, and retiring THOSE is
 * Stage 2d-5b's job, not this one's. Checked here only as a pre-flight
 * (see `assertNotFkReferenced()`) in case that assumption is ever wrong —
 * MySQL refuses to drop an index still backing a foreign key, and a clear
 * message here beats a bare "needed in a foreign key constraint" error.
 *
 * `crm_stages` carries no such user-keyed name/role-adjacent unique
 * outside its own `(user_id, id)` one (role-uniqueness is enforced in
 * `CrmStage::booted()`'s `saving` hook, never a DB constraint — see that
 * model's own docblock), so it is untouched here.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, columns: list<string>, name: string, replacement: string, excludeNull: ?string}>
     */
    private const INDEXES = [
        [
            'table' => 'crm_workspaces',
            'columns' => ['user_id', 'name'],
            'name' => 'crm_workspaces_user_id_name_unique',
            'replacement' => 'crm_workspaces_team_name_unique',
            'excludeNull' => null,
        ],
        [
            'table' => 'customers',
            'columns' => ['user_id', 'phone_key'],
            'name' => 'customers_user_id_phone_key_unique',
            'replacement' => 'customers_team_phone_key_unique',
            'excludeNull' => 'phone_key',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index) {
            $this->assertReplacementExists($index);
            $this->assertNotFkReferenced($index);
        }

        foreach (self::INDEXES as $index) {
            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->dropUnique($index['name']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $index) {
            $this->assertNoDuplicates($index);
        }

        foreach (self::INDEXES as $index) {
            Schema::table($index['table'], function (Blueprint $table) use ($index) {
                $table->unique($index['columns'], $index['name']);
            });
        }
    }

    /**
     * Refuses to drop the old index if its team-keyed replacement is
     * somehow missing — dropping the only uniqueness guarantee a table has
     * left would be strictly worse than leaving the old one in place.
     */
    private function assertReplacementExists(array $index): void
    {
        $names = collect(Schema::getIndexes($index['table']))->pluck('name');

        if (! $names->contains($index['replacement'])) {
            throw new RuntimeException(sprintf(
                '%s: refusing to drop %s — its replacement %s does not exist on this schema. '.
                'Run Stage 2d-3-1\'s migration first.',
                $index['table'],
                $index['name'],
                $index['replacement'],
            ));
        }
    }

    /**
     * MySQL refuses `DROP INDEX` outright when the index is the ONLY one
     * covering a foreign key's referenced columns — a confusing error
     * ("needed in a foreign key constraint") if it ever fires here, since
     * nothing about these two particular indexes is expected to back a FK
     * (see this file's own docblock: the composite FKs on `crm_workspaces`
     * reference `(user_id, id)` / `(team_id, id)` via
     * `crm_workspaces_owner_id_unique` / `crm_workspaces_team_id_unique`, a
     * DIFFERENT pair of indexes, not this one — and `customers` has no FK
     * referencing `(user_id, phone_key)` at all). Checked anyway,
     * explicitly, by comparing every incoming FK's referenced-column set
     * (there is no `information_schema` column naming which INDEX a FK
     * resolved against, only which COLUMNS — a FK needs any covering
     * unique index, not a specific one) against this index's own columns,
     * so a schema that drifted from that assumption fails with a message
     * that says why, not a raw driver error. SQLite's `dropUnique()` never
     * touches a foreign key either way (see the FK migrations' own
     * docblocks on SQLite's full-table-rebuild semantics), so this is a
     * MySQL-only check — harmless, not merely inert, on SQLite.
     */
    private function assertNotFkReferenced(array $index): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $rows = DB::select(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE '.
            'WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? '.
            'ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            [$index['table']]
        );

        $byConstraint = collect($rows)->groupBy('CONSTRAINT_NAME')
            ->map(fn ($group) => $group->pluck('COLUMN_NAME')->all());

        $match = $byConstraint->first(fn ($columns) => $columns === $index['columns']);

        if ($match !== null) {
            throw new RuntimeException(sprintf(
                '%s: a foreign key still references exactly (%s) — cannot drop %s. '.
                'That foreign key must be retired first (Stage 2d-5b).',
                $index['table'],
                implode(', ', $index['columns']),
                $index['name'],
            ));
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
                '%s: %d duplicate group(s) on (%s) — cannot recreate unique index %s. '.
                'Resolve the duplicates before rolling back this migration.',
                $index['table'],
                $duplicateGroups->count(),
                implode(', ', $index['columns']),
                $index['name'],
            ));
        }
    }
};
