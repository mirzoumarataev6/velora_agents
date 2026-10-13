<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Stage 2d-2: team_id is NOT NULL on all 26 StampsTeam tables. No MySQL-only
 * exemption was needed for any group — verified empirically (see each
 * migration's own docblock) before writing them, so there is no exempt
 * list to carry here; every one of the 26 is checked the same way.
 */
class TeamIdNotNullTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'agents', 'usage_logs', 'bi_sources', 'bi_datasets', 'bi_columns',
        'bi_dashboards', 'bi_widgets', 'bi_connections', 'bi_chat_messages',
        'tasks', 'crm_notes', 'customer_merges', 'deal_activities',
        'knowledge_sources', 'channels', 'knowledge_chunks', 'conversations',
        'leads', 'datasets', 'dataset_products', 'database_connections', 'product_images',
        'crm_workspaces', 'crm_stages', 'customers', 'deals',
    ];

    public function test_team_id_is_not_nullable_on_every_one_of_the_26_tables(): void
    {
        $this->assertCount(26, self::TABLES, 'The table list itself drifted from the 26 StampsTeam tables.');

        foreach (self::TABLES as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'team_id');

            $this->assertNotNull($column, "{$table} has no team_id column at all.");
            $this->assertFalse($column['nullable'], "{$table}.team_id is still nullable.");
        }
    }

    /** @return array<string, array{0: string}> one representative table per group */
    public static function representativeTableProvider(): array
    {
        return [
            'group (a) — independent' => ['tasks'],
            'group (b) — crm_workspaces/crm_stages' => ['crm_workspaces'],
            'group (c) — customers/deals' => ['deals'],
        ];
    }

    #[DataProvider('representativeTableProvider')]
    public function test_a_raw_insert_with_team_id_null_is_refused(string $table): void
    {
        $user = User::factory()->create();

        $row = match ($table) {
            'tasks' => ['user_id' => $user->id, 'title' => 'x', 'created_at' => now(), 'updated_at' => now()],
            'crm_workspaces' => ['user_id' => $user->id, 'name' => 'custom', 'created_at' => now(), 'updated_at' => now()],
            'deals' => [
                'user_id' => $user->id,
                'customer_id' => Customer::factory()->for($user)->create()->id,
                'created_at' => now(), 'updated_at' => now(),
            ],
        };
        $row['team_id'] = null;

        $this->expectException(QueryException::class);

        DB::table($table)->insert($row);
    }

    /**
     * The precedent-1 regression, named directly in the task: deleting a
     * user who owns a deal, a customer, a workspace and stages must not
     * raise a foreign key error. This is the exact cascade
     * 2026_10_17_000007's docblock reproduced once, now re-proven against
     * the NOT NULL schema these three migrations leave behind.
     */
    public function test_deleting_a_user_who_owns_a_deal_customer_workspace_and_stages_does_not_raise_an_fk_error(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $workspace = CrmWorkspace::defaultFor($user);
        $stage = CrmStage::initialFor($user);
        $customer = Customer::factory()->for($user)->create(['crm_workspace_id' => $workspace->id]);
        Deal::factory()->for($user)->create([
            'customer_id' => $customer->id,
            'crm_workspace_id' => $workspace->id,
            'crm_stage_id' => $stage->id,
            'agent_id' => $agent->id,
        ]);

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('deals', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('customers', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('crm_workspaces', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('crm_stages', ['user_id' => $user->id]);
    }

    /**
     * @return array<string, array{0: string, 1: string}> migration file that
     *                                                    both relaxes AND
     *                                                    re-guards => a
     *                                                    table it covers
     */
    public static function migrationProvider(): array
    {
        return [
            // Group (a) and (c): on SQLite, the same file both performs the
            // real schema change and relaxes it back via down() — a
            // single, uniform round trip to test.
            'group (a)' => ['2026_10_18_000001_make_team_id_not_null_on_independent_tables.php', 'tasks'],
            'group (c)' => ['2026_10_18_000003_make_team_id_not_null_on_customers_and_deals.php', 'customers'],
        ];
    }

    /**
     * The pre-flight guard: relax the table back to nullable (undoing
     * RefreshDatabase's own migration run for this one table), leave a
     * row NULL on purpose, then run the migration's up() again. The guard
     * must throw before touching that table's own schema — proven by
     * asserting the column is STILL nullable afterward.
     */
    #[DataProvider('migrationProvider')]
    public function test_the_pre_flight_guard_throws_and_leaves_the_schema_untouched(string $file, string $table): void
    {
        (require database_path('migrations/'.$file))->down();

        $user = User::factory()->create();

        $rowId = match ($table) {
            'tasks' => Task::factory()->for($user)->create()->id,
            'customers' => Customer::factory()->for($user)->create()->id,
        };
        DB::table($table)->where('id', $rowId)->update(['team_id' => null]);

        $this->assertTrue(
            DB::table($table)->whereNull('team_id')->exists(),
            "Could not create a NULL team_id row on {$table} to test the guard against."
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($table, '/').': \d+ row\(s\) still have a NULL team_id/');

        try {
            (require database_path('migrations/'.$file))->up();
        } finally {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'team_id');
            $this->assertTrue($column['nullable'], "{$table}'s schema was altered despite the guard throwing.");
        }
    }

    /**
     * Group (b) on SQLite is a special case: its migration's schema change
     * is a deliberate no-op on this driver (see its own docblock) — only
     * group (c)'s migration actually flips crm_workspaces/crm_stages here.
     * The guard check itself is still unconditional code in group (b)'s
     * up(), run before that driver branch, so it is tested directly
     * rather than through the shared provider above (which also asserts
     * "schema stays untouched," not a meaningful check for a migration
     * that never touches this driver's schema at all).
     */
    public function test_group_bs_guard_throws_on_sqlite_even_though_its_own_schema_change_is_a_no_op_there(): void
    {
        // crm_workspaces' SQLite nullability is actually controlled by
        // group (c), not group (b) — relax via the file that controls it.
        (require database_path('migrations/2026_10_18_000003_make_team_id_not_null_on_customers_and_deals.php'))->down();

        $user = User::factory()->create();
        $workspace = CrmWorkspace::defaultFor($user);
        DB::table('crm_workspaces')->where('id', $workspace->id)->update(['team_id' => null]);

        $this->assertTrue(DB::table('crm_workspaces')->whereNull('team_id')->exists());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/crm_workspaces: \d+ row\(s\) still have a NULL team_id/');

        (require database_path('migrations/2026_10_18_000002_make_team_id_not_null_on_crm_workspaces_and_stages.php'))->up();
    }
}
