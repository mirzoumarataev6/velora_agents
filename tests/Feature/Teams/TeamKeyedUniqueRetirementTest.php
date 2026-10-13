<?php

namespace Tests\Feature\Teams;

use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Stage 2d-5a: drops the two OLD user-keyed uniques —
 * `crm_workspaces_user_id_name_unique` and
 * `customers_user_id_phone_key_unique` — now that their team-keyed
 * replacements (Stage 2d-3-1) are what every read/write path actually
 * uses. Schema-only, same convention as `TeamKeyedCrmSchemaTest`.
 */
class TeamKeyedUniqueRetirementTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_20_000001_drop_user_keyed_name_and_phone_key_uniques.php';

    /** Each migration file's anonymous class is `require`d fresh per call — never cached across calls in one test. */
    private function freshMigration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    public function test_the_two_old_uniques_are_gone_and_their_team_keyed_replacements_remain(): void
    {
        $workspaceIndexes = collect(Schema::getIndexes('crm_workspaces'))->pluck('name');
        $customerIndexes = collect(Schema::getIndexes('customers'))->pluck('name');

        $this->assertFalse($workspaceIndexes->contains('crm_workspaces_user_id_name_unique'));
        $this->assertFalse($customerIndexes->contains('customers_user_id_phone_key_unique'));

        $this->assertTrue($workspaceIndexes->contains('crm_workspaces_team_name_unique'));
        $this->assertTrue($customerIndexes->contains('customers_team_phone_key_unique'));

        // Out of scope for this stage (Stage 2d-5b): crm_workspaces' OTHER
        // user-keyed unique, which the old composite FKs still reference.
        $this->assertTrue($workspaceIndexes->contains('crm_workspaces_owner_id_unique'));
    }

    public function test_two_teams_of_one_owner_can_each_have_a_workspace_of_the_same_name(): void
    {
        $owner = User::factory()->create();
        $teamA = $owner->current_team_id;
        $teamB = Team::factory()->create()->id;

        CrmWorkspace::withoutTeamStamping(fn () => CrmWorkspace::forceCreate([
            'user_id' => $owner->id, 'team_id' => $teamA, 'name' => 'Sales', 'color' => 'blue',
        ]));

        $allowed = CrmWorkspace::withoutTeamStamping(fn () => CrmWorkspace::forceCreate([
            'user_id' => $owner->id, 'team_id' => $teamB, 'name' => 'Sales', 'color' => 'green',
        ]));

        $this->assertNotNull($allowed->id);
        $this->assertSame(2, CrmWorkspace::where('user_id', $owner->id)->where('name', 'Sales')->count());
    }

    public function test_the_same_phone_key_can_now_exist_in_two_teams_of_one_owner(): void
    {
        $owner = User::factory()->create();
        $teamA = $owner->current_team_id;
        $teamB = Team::factory()->create()->id;

        Customer::withoutTeamStamping(fn () => Customer::forceCreate([
            'user_id' => $owner->id, 'team_id' => $teamA, 'name' => 'Azim', 'phone_key' => '900112233',
        ]));

        $allowed = Customer::withoutTeamStamping(fn () => Customer::forceCreate([
            'user_id' => $owner->id, 'team_id' => $teamB, 'name' => 'Azim (B)', 'phone_key' => '900112233',
        ]));

        $this->assertNotNull($allowed->id);
        $this->assertSame(2, Customer::where('user_id', $owner->id)->where('phone_key', '900112233')->count());
    }

    public function test_up_preflight_aborts_and_drops_nothing_when_the_team_keyed_replacement_is_missing(): void
    {
        // Simulate "as if 2d-5a never ran": the old index back, the
        // team-keyed replacement gone.
        Schema::table('crm_workspaces', function ($table) {
            $table->unique(['user_id', 'name'], 'crm_workspaces_user_id_name_unique');
            $table->dropUnique('crm_workspaces_team_name_unique');
        });

        try {
            $this->freshMigration()->up();
            $this->fail('Expected the missing-replacement pre-flight to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('crm_workspaces_team_name_unique', $e->getMessage());
        }

        // The old index this run was about to drop is untouched — the
        // pre-flight runs for every index BEFORE any index is dropped.
        $names = collect(Schema::getIndexes('crm_workspaces'))->pluck('name');
        $this->assertTrue($names->contains('crm_workspaces_user_id_name_unique'));
    }

    public function test_down_recreates_both_old_indexes_with_the_right_shape(): void
    {
        $this->freshMigration()->down();

        $workspaceIndexes = collect(Schema::getIndexes('crm_workspaces'));
        $customerIndexes = collect(Schema::getIndexes('customers'));

        $this->assertTrue(
            $workspaceIndexes->contains(fn ($i) => $i['name'] === 'crm_workspaces_user_id_name_unique' && $i['unique'] && $i['columns'] === ['user_id', 'name']),
        );
        $this->assertTrue(
            $customerIndexes->contains(fn ($i) => $i['name'] === 'customers_user_id_phone_key_unique' && $i['unique'] && $i['columns'] === ['user_id', 'phone_key']),
        );
    }

    public function test_down_preflight_aborts_on_a_duplicate_created_by_two_teams_sharing_one_owner(): void
    {
        $owner = User::factory()->create();
        $teamB = Team::factory()->create()->id;

        // Only possible BECAUSE this stage dropped unique(user_id, name) —
        // the exact duplicate down() must refuse to paper over.
        CrmWorkspace::withoutTeamStamping(fn () => CrmWorkspace::forceCreate([
            'user_id' => $owner->id, 'team_id' => $owner->current_team_id, 'name' => 'Duplicate', 'color' => 'blue',
        ]));
        CrmWorkspace::withoutTeamStamping(fn () => CrmWorkspace::forceCreate([
            'user_id' => $owner->id, 'team_id' => $teamB, 'name' => 'Duplicate', 'color' => 'green',
        ]));

        try {
            $this->freshMigration()->down();
            $this->fail('Expected the duplicate pre-flight to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('crm_workspaces', $e->getMessage());
            $this->assertStringContainsString('1 duplicate group', $e->getMessage());
        }

        $names = collect(Schema::getIndexes('crm_workspaces'))->pluck('name');
        $this->assertFalse($names->contains('crm_workspaces_user_id_name_unique'));
    }
}
