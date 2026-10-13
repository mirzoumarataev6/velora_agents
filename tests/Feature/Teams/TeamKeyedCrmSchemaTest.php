<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Stage 2d-3 added four team-keyed unique indexes and three composite FKs.
 * Stages 2d-5a/5b later retired the competing user-keyed constraints.
 * Nothing here exercises application code — this is schema-only, so the
 * tests are schema-only too.
 */
class TeamKeyedCrmSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_MIGRATION = '2026_10_19_000001_add_team_keyed_unique_indexes_to_crm_and_customers.php';

    private const FK_MIGRATION = '2026_10_19_000002_add_team_keyed_composite_fks_to_deals_and_customers.php';

    /** Each migration file's anonymous class is `require`d fresh per call — never cached across calls in one test. */
    private function freshMigration(string $filename): Migration
    {
        return require database_path('migrations/'.$filename);
    }

    // --- (a) the four unique indexes exist ----------------------------------

    public function test_the_four_team_keyed_unique_indexes_exist(): void
    {
        $workspaceIndexes = collect(Schema::getIndexes('crm_workspaces'));
        $stageIndexes = collect(Schema::getIndexes('crm_stages'));
        $customerIndexes = collect(Schema::getIndexes('customers'));

        $this->assertTrue(
            $workspaceIndexes->contains(fn ($i) => $i['name'] === 'crm_workspaces_team_id_unique' && $i['unique'] && $i['columns'] === ['team_id', 'id']),
            'crm_workspaces_team_id_unique is missing or shaped wrong.'
        );
        $this->assertTrue(
            $workspaceIndexes->contains(fn ($i) => $i['name'] === 'crm_workspaces_team_name_unique' && $i['unique'] && $i['columns'] === ['team_id', 'name']),
            'crm_workspaces_team_name_unique is missing or shaped wrong.'
        );
        $this->assertTrue(
            $stageIndexes->contains(fn ($i) => $i['name'] === 'crm_stages_team_id_unique' && $i['unique'] && $i['columns'] === ['team_id', 'id']),
            'crm_stages_team_id_unique is missing or shaped wrong.'
        );
        $this->assertTrue(
            $customerIndexes->contains(fn ($i) => $i['name'] === 'customers_team_phone_key_unique' && $i['unique'] && $i['columns'] === ['team_id', 'phone_key']),
            'customers_team_phone_key_unique is missing or shaped wrong.'
        );

        // crm_workspaces_user_id_name_unique and customers_user_id_phone_key_unique
        // used to be asserted present here too ("this stage adds, it never
        // drops") — Stage 2d-5a now drops both; see TeamKeyedUniqueRetirementTest
        // for that migration's own coverage.
    }

    /**
     * SQLite never reports a foreign key's constraint NAME at all
     * (`Schema::getForeignKeys()` returns `name: null` for every one, on
     * every table, in this schema) — only MySQL does. So this matches by
     * shape (columns / foreign table / foreign columns / delete rule),
     * which both drivers report, rather than by name.
     */
    private function fkShapeExists(array $fks, array $columns, string $foreignTable, array $foreignColumns, string $onDelete = 'restrict'): bool
    {
        return collect($fks)->contains(
            fn ($fk) => $fk['columns'] === $columns
                && $fk['foreign_table'] === $foreignTable
                && $fk['foreign_columns'] === $foreignColumns
                && $fk['on_delete'] === $onDelete
        );
    }

    public function test_the_three_team_keyed_composite_fks_exist(): void
    {
        $dealFks = Schema::getForeignKeys('deals');
        $customerFks = Schema::getForeignKeys('customers');

        $this->assertTrue($this->fkShapeExists($dealFks, ['team_id', 'crm_workspace_id'], 'crm_workspaces', ['team_id', 'id']));
        $this->assertTrue($this->fkShapeExists($dealFks, ['team_id', 'crm_stage_id'], 'crm_stages', ['team_id', 'id']));
        $this->assertTrue($this->fkShapeExists($customerFks, ['team_id', 'crm_workspace_id'], 'crm_workspaces', ['team_id', 'id']));

        // Stage 2d-5b retires the three old user-keyed FKs; the team
        // constraints above now provide the only composite tenant guard.
        $this->assertFalse($this->fkShapeExists($dealFks, ['user_id', 'crm_workspace_id'], 'crm_workspaces', ['user_id', 'id']));
        $this->assertFalse($this->fkShapeExists($dealFks, ['user_id', 'crm_stage_id'], 'crm_stages', ['user_id', 'id']));
        $this->assertFalse($this->fkShapeExists($customerFks, ['user_id', 'crm_workspace_id'], 'crm_workspaces', ['user_id', 'id']));
    }

    // --- (b) the unique(team_id, name) actually separates teams -------------

    /**
     * Raw inserts isolate the database constraint from the model's team
     * stamping and active-team logic: a duplicate name fails within one
     * team and succeeds across two teams, independent of the acting user.
     */
    public function test_same_workspace_name_is_refused_within_a_team_and_allowed_across_teams(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $userA1 = User::factory()->create();
        $userA2 = User::factory()->create();
        $userB1 = User::factory()->create();

        DB::table('crm_workspaces')->insert([
            'user_id' => $userA1->id,
            'team_id' => $teamA->id,
            'name' => 'Sales',
            'color' => 'blue',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Same team, different user, same name -> refused by
        // crm_workspaces_team_name_unique.
        $this->expectException(QueryException::class);

        try {
            DB::table('crm_workspaces')->insert([
                'user_id' => $userA2->id,
                'team_id' => $teamA->id,
                'name' => 'Sales',
                'color' => 'violet',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            // Different team, same name -> allowed, proven independently of
            // the exception above so a wrong assertion order can't hide it.
            $allowed = DB::table('crm_workspaces')->insert([
                'user_id' => $userB1->id,
                'team_id' => $teamB->id,
                'name' => 'Sales',
                'color' => 'green',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertTrue($allowed);
        }
    }

    // --- (c) pre-flights throw and leave the schema untouched ---------------

    public function test_the_unique_index_preflight_throws_on_a_duplicate_and_creates_no_index(): void
    {
        Schema::table('crm_workspaces', function ($table) {
            $table->dropUnique('crm_workspaces_team_name_unique');
        });

        $teamA = Team::factory()->create();
        $userA1 = User::factory()->create();
        $userA2 = User::factory()->create();

        foreach ([$userA1, $userA2] as $user) {
            DB::table('crm_workspaces')->insert([
                'user_id' => $user->id,
                'team_id' => $teamA->id,
                'name' => 'Duplicate',
                'color' => 'blue',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            $this->freshMigration(self::INDEX_MIGRATION)->up();
            $this->fail('Expected the duplicate pre-flight to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('crm_workspaces', $e->getMessage());
            $this->assertStringContainsString('1 duplicate group', $e->getMessage());
        }

        // The index that had a duplicate was never recreated — the dropped
        // index stays dropped, nothing papers over the duplicate.
        $names = collect(Schema::getIndexes('crm_workspaces'))->pluck('name');
        $this->assertFalse($names->contains('crm_workspaces_team_name_unique'));
    }

    public function test_the_composite_fk_preflight_throws_on_a_team_mismatch_and_creates_no_fk(): void
    {
        // Dropped by COLUMN array, not by name: SQLite never reports (and
        // Laravel's SQLite grammar cannot drop by) a foreign key's name —
        // see fkShapeExists()'s docblock above.
        Schema::table('deals', function ($table) {
            $table->dropForeign(['team_id', 'crm_workspace_id']);
            $table->dropForeign(['team_id', 'crm_stage_id']);
        });

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $workspace = CrmWorkspace::defaultFor($user);
        $stage = CrmStage::initialFor($user);
        $customer = Customer::factory()->for($user)->create(['crm_workspace_id' => $workspace->id]);
        $deal = Deal::factory()->for($user)->create([
            'customer_id' => $customer->id,
            'crm_workspace_id' => $workspace->id,
            'crm_stage_id' => $stage->id,
            'agent_id' => $agent->id,
        ]);

        // Force a mismatch: the deal's team_id no longer matches its own
        // workspace's team_id.
        $otherTeam = Team::factory()->create();
        DB::table('deals')->where('id', $deal->id)->update(['team_id' => $otherTeam->id]);

        try {
            $this->freshMigration(self::FK_MIGRATION)->up();
            $this->fail('Expected the team-mismatch pre-flight to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('deals_team_workspace_fk', $e->getMessage());
            $this->assertStringContainsString('1 row', $e->getMessage());
        }

        $dealFks = Schema::getForeignKeys('deals');
        $this->assertFalse($this->fkShapeExists($dealFks, ['team_id', 'crm_workspace_id'], 'crm_workspaces', ['team_id', 'id']));
        $this->assertFalse($this->fkShapeExists($dealFks, ['team_id', 'crm_stage_id'], 'crm_stages', ['team_id', 'id']));
    }
}
