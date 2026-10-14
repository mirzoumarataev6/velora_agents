<?php

namespace Tests\Feature\Teams;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `2026_10_24_000001` is MySQL-only cleanup (see its own docblock): it
 * drops an index SQLite never had in the first place, because SQLite never
 * auto-creates a same-named index behind a foreign key the way InnoDB
 * does. These tests prove the driver/existence guards make `up()` and
 * `down()` true no-ops on this suite's own connection — they cannot
 * exercise the MySQL branch at all, on purpose: `phpunit.xml` forces
 * sqlite `:memory:` (see `TestCase::guardAgainstRunningOnARealDatabase()`).
 * The actual drop against a real MySQL schema was verified separately,
 * against a disposable `agent_scratch_a` copy, never the real `agent`
 * database — see the hardening report for that output.
 */
class DealsUserStageFkIndexCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_24_000001_drop_orphaned_deals_user_stage_fk_index.php';

    /** Each migration file's anonymous class is `require`d fresh per call — never cached across calls in one test. */
    private function freshMigration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    public function test_up_is_a_no_op_on_sqlite_and_leaves_every_deals_index_untouched(): void
    {
        $before = collect(Schema::getIndexes('deals'))->pluck('name')->sort()->values()->all();

        $this->freshMigration()->up();

        $after = collect(Schema::getIndexes('deals'))->pluck('name')->sort()->values()->all();
        $this->assertSame($before, $after);
    }

    public function test_up_is_safe_to_run_more_than_once(): void
    {
        $migration = $this->freshMigration();
        $migration->up();
        $migration->up();

        $this->addToAssertionCount(1);
    }

    public function test_down_is_a_no_op(): void
    {
        $before = collect(Schema::getIndexes('deals'))->pluck('name')->sort()->values()->all();

        $this->freshMigration()->down();

        $after = collect(Schema::getIndexes('deals'))->pluck('name')->sort()->values()->all();
        $this->assertSame($before, $after);
    }

    /**
     * Not a MySQL-index test — a regression guard that this cleanup never
     * touches the FK that actually enforces tenant isolation on `deals`
     * today.
     */
    public function test_the_team_keyed_stage_fk_still_enforces_tenant_isolation_after_this_migration(): void
    {
        $this->freshMigration()->up();

        $this->assertTrue(collect(Schema::getForeignKeys('deals'))->contains(
            fn ($fk) => $fk['columns'] === ['team_id', 'crm_stage_id']
                && $fk['foreign_table'] === 'crm_stages'
                && $fk['foreign_columns'] === ['team_id', 'id']
        ));
    }
}
