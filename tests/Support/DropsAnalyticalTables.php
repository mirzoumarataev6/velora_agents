<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cleans up the `bi_ds_*` tables a test created.
 *
 * WHY THIS IS NEEDED AT ALL is the interesting part, and it is the hazard the
 * Phase 0 review flagged: `RefreshDatabase` works by running the MIGRATIONS, and
 * no migration knows about a table that was created at runtime. A dataset
 * materialized in one test is therefore invisible to the usual cleanup.
 *
 * On SQLite — which `TestCase::guardAgainstRunningOnARealDatabase()` pins the
 * suite to — DDL is transactional, so a table created inside a test's transaction
 * is usually rolled back with everything else. "Usually" is not a property worth
 * relying on: a test that commits, or one that uses `RefreshDatabase` without a
 * transaction, would leak a table into the next test and make a row count from the
 * PREVIOUS test's data look like a passing assertion. So this drops them
 * explicitly and asserts nothing was left behind.
 *
 * It also documents the production counterpart: BiDatasetObserver →
 * DropDatasetTableJob is what does this job for real datasets, and the same
 * `/^bi_ds_\d+$/` shape is all either of them will ever drop.
 */
trait DropsAnalyticalTables
{
    protected function tearDown(): void
    {
        $this->dropAnalyticalTables();

        parent::tearDown();
    }

    /**
     * Drop every runtime analytical table currently present.
     *
     * Names come from the database's own catalog and are filtered to the one shape
     * a BI table may have, so this can never drop an application table even if the
     * pattern query returns something unexpected.
     */
    protected function dropAnalyticalTables(): void
    {
        foreach ($this->analyticalTableNames() as $table) {
            if (preg_match('/^bi_ds_\d+$/', $table) === 1) {
                Schema::dropIfExists($table);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function analyticalTableNames(): array
    {
        $driver = DB::connection()->getDriverName();

        // Asked of the catalog rather than of `Schema::getTables()`, which on some
        // driver versions is far slower because it collects sizes and comments for
        // every table in the schema.
        $rows = match ($driver) {
            'sqlite' => DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'bi_ds_%'"),
            'mysql', 'mariadb' => DB::select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', ['bi_ds_%']),
            default => [],
        };

        return array_values(array_map(
            fn ($row) => (string) ((array) $row)['name'],
            $rows
        ));
    }
}
