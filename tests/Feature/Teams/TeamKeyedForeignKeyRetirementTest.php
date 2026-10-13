<?php

namespace Tests\Feature\Teams;

use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TeamKeyedForeignKeyRetirementTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_21_000001_retire_user_keyed_crm_foreign_keys.php';

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function hasForeignKey(string $table, array $columns, string $foreignTable, array $foreignColumns): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn ($fk) => $fk['columns'] === $columns
            && $fk['foreign_table'] === $foreignTable
            && $fk['foreign_columns'] === $foreignColumns);
    }

    private function joinOwnerTeam(User $owner, User $teammate): void
    {
        $role = Role::where('team_id', $owner->current_team_id)
            ->where('slug', PermissionCatalog::OPERATOR)
            ->sole();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $teammate->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
    }

    public function test_only_team_keyed_workspace_and_stage_constraints_remain(): void
    {
        $workspaceIndexes = collect(Schema::getIndexes('crm_workspaces'))->pluck('name');
        $stageIndexes = collect(Schema::getIndexes('crm_stages'))->pluck('name');

        $this->assertFalse($workspaceIndexes->contains('crm_workspaces_owner_id_unique'));
        $this->assertFalse($stageIndexes->contains('crm_stages_owner_id_unique'));
        $this->assertTrue($workspaceIndexes->contains('crm_workspaces_team_id_unique'));
        $this->assertTrue($stageIndexes->contains('crm_stages_team_id_unique'));

        foreach ([
            ['deals', ['user_id', 'crm_workspace_id'], 'crm_workspaces'],
            ['deals', ['user_id', 'crm_stage_id'], 'crm_stages'],
            ['customers', ['user_id', 'crm_workspace_id'], 'crm_workspaces'],
        ] as [$table, $columns, $parent]) {
            $this->assertFalse($this->hasForeignKey($table, $columns, $parent, ['user_id', 'id']));
            $this->assertTrue($this->hasForeignKey($table, ['team_id', $columns[1]], $parent, ['team_id', 'id']));
        }
    }

    public function test_a_teammate_can_attach_a_customer_and_deal_to_another_members_workspace_and_stage(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create();
        $this->joinOwnerTeam($owner, $teammate);
        $team = $owner->current_team_id;
        $workspace = CrmWorkspace::defaultFor($owner, $team);
        $stage = CrmStage::initialFor($owner, $team);

        $customer = Customer::withoutTeamStamping(fn () => Customer::forceCreate([
            'user_id' => $teammate->id,
            'team_id' => $team,
            'name' => 'Shared customer',
            'crm_workspace_id' => $workspace->id,
        ]));

        $deal = Deal::withoutTeamStamping(fn () => Deal::forceCreate([
            'user_id' => $teammate->id,
            'team_id' => $team,
            'customer_id' => $customer->id,
            'crm_workspace_id' => $workspace->id,
            'crm_stage_id' => $stage->id,
            'stage' => $stage->name,
            'stage_role' => $stage->role,
        ]));

        $this->assertSame($team, $customer->team_id);
        $this->assertSame($team, $deal->team_id);
    }

    public function test_up_refuses_to_remove_old_constraints_when_a_team_replacement_is_missing(): void
    {
        $this->migration()->down();
        Schema::table('customers', fn ($table) => $table->dropForeign(['team_id', 'crm_workspace_id']));

        try {
            $this->migration()->up();
            $this->fail('Expected the missing team FK to stop the migration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('customers_team_workspace_fk', $e->getMessage());
        }

        $this->assertTrue($this->hasForeignKey('deals', ['user_id', 'crm_workspace_id'], 'crm_workspaces', ['user_id', 'id']));
    }

    public function test_down_preflight_refuses_cross_member_references_without_changing_schema(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create();
        $this->joinOwnerTeam($owner, $teammate);
        $team = $owner->current_team_id;
        $workspace = CrmWorkspace::defaultFor($owner, $team);

        Customer::withoutTeamStamping(fn () => Customer::forceCreate([
            'user_id' => $teammate->id,
            'team_id' => $team,
            'name' => 'Shared customer',
            'crm_workspace_id' => $workspace->id,
        ]));

        try {
            $this->migration()->down();
            $this->fail('Expected the cross-member reference to block rollback.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('customers_user_workspace_fk', $e->getMessage());
        }

        $this->assertFalse(collect(Schema::getIndexes('crm_workspaces'))->pluck('name')->contains('crm_workspaces_owner_id_unique'));
    }

    public function test_down_restores_old_foreign_keys_and_indexes_when_data_is_compatible(): void
    {
        $this->migration()->down();

        $this->assertTrue(collect(Schema::getIndexes('crm_workspaces'))->pluck('name')->contains('crm_workspaces_owner_id_unique'));
        $this->assertTrue(collect(Schema::getIndexes('crm_stages'))->pluck('name')->contains('crm_stages_owner_id_unique'));
        $this->assertTrue($this->hasForeignKey('deals', ['user_id', 'crm_workspace_id'], 'crm_workspaces', ['user_id', 'id']));
        $this->assertTrue($this->hasForeignKey('deals', ['user_id', 'crm_stage_id'], 'crm_stages', ['user_id', 'id']));
        $this->assertTrue($this->hasForeignKey('customers', ['user_id', 'crm_workspace_id'], 'crm_workspaces', ['user_id', 'id']));
    }
}
