<?php

namespace Tests\Feature\Teams;

use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Crm\CustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamCrmAccessTest extends TestCase
{
    use RefreshDatabase;

    private function member(User $owner, string $role): User
    {
        $user = User::factory()->create();
        TeamMembership::create(['user_id' => $user->id, 'team_id' => $owner->current_team_id,
            'role_id' => Role::where('team_id', $owner->current_team_id)->where('slug', $role)->sole()->id,
            'joined_at' => now()]);
        $user->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $user;
    }

    public function test_shared_crm_defaults_are_reused_across_members(): void
    {
        $owner = User::factory()->create();
        $admin = $this->member($owner, 'admin');
        $workspace = CrmWorkspace::defaultFor($owner, $owner->current_team_id);
        $stages = CrmStage::defaultsFor($owner, $owner->current_team_id);
        $this->assertSame($workspace->id, CrmWorkspace::defaultFor($admin, $admin->current_team_id)->id);
        $this->assertSame($stages->modelKeys(), CrmStage::defaultsFor($admin, $admin->current_team_id)->modelKeys());
        $this->actingAs($admin)->get(route('crm.board'))->assertOk();
        $this->assertDatabaseCount('crm_workspaces', 1);
        $this->assertDatabaseCount('crm_stages', 4);
    }

    public function test_viewer_cannot_create_deals_or_reorder_stages(): void
    {
        $owner = User::factory()->create();
        $viewer = $this->member($owner, 'viewer');
        $this->actingAs($viewer)->postJson(route('crm.deals.store'), [])->assertForbidden();
        $this->postJson(route('crm.stages.reorder'), [])->assertForbidden();
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('crm_stages', 0);
    }

    public function test_revoked_analytics_view_blocks_all_crm_lists_before_seeding(): void
    {
        $owner = User::factory()->create();
        $viewer = $this->member($owner, 'viewer');
        $viewer->membershipIn($owner->current_team_id)->role->syncPermissions([]);
        $viewer->flushPermissionCache();
        foreach (['crm.board', 'crm.deals.poll', 'crm.customers.index', 'crm.customers.lookup', 'crm.customers.search', 'crm.tasks.index', 'crm.stages.index'] as $route) {
            $this->actingAs($viewer)->getJson(route($route))->assertForbidden();
        }
        $this->assertDatabaseCount('crm_workspaces', 0);
    }

    public function test_edits_and_notes_use_the_resource_team_even_after_navigation_changes(): void
    {
        $owner = User::factory()->create();
        $admin = $this->member($owner, 'admin');
        $customer = Customer::factory()->create(['user_id' => $owner->id]);
        $deal = Deal::factory()->create(['user_id' => $owner->id, 'customer_id' => $customer->id]);
        $sharedWorkspace = CrmWorkspace::defaultFor($owner, $owner->current_team_id);
        $personalTeam = $admin->teams()->where('teams.id', '!=', $owner->current_team_id)->sole();
        $admin->forceFill(['current_team_id' => $personalTeam->id])->save();
        $foreignWorkspace = CrmWorkspace::defaultFor($admin, $personalTeam);
        $this->actingAs($admin)->patchJson(route('crm.customers.update', $customer), ['crm_workspace_id' => $foreignWorkspace->id])->assertUnprocessable();
        $this->patchJson(route('crm.customers.update', $customer), ['crm_workspace_id' => $sharedWorkspace->id])->assertOk();
        $this->postJson(route('crm.deals.notes.store', $deal), ['kind' => 'note', 'body' => 'Shared note'])->assertCreated();
        $this->assertDatabaseHas('crm_notes', ['user_id' => $admin->id, 'team_id' => $owner->current_team_id, 'body' => 'Shared note']);
    }

    public function test_merge_uses_team_boundary_and_supports_different_creators(): void
    {
        $owner = User::factory()->create();
        $admin = $this->member($owner, 'admin');
        $into = Customer::factory()->create(['user_id' => $owner->id]);
        $from = Customer::factory()->create(['user_id' => $admin->id, 'team_id' => $owner->current_team_id, 'phone_key' => null]);
        $this->actingAs($admin)->post(route('crm.customers.merge', $into), ['customer_id' => $from->id])->assertRedirect();
        $this->assertSoftDeleted($from);
        $foreign = Customer::factory()->create(['user_id' => $owner->id, 'team_id' => $admin->teams()->where('teams.id', '!=', $owner->current_team_id)->sole()->id]);
        app(CustomerResolver::class)->merge($foreign, $into);
        $this->assertNotSoftDeleted($foreign);
    }
}
