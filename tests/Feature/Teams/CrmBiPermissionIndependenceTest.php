<?php

namespace Tests\Feature\Teams;

use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage B's whole point, proven end to end: crm.* and analytics.* must now
 * be independently grantable. Each half of this file is the other's
 * mirror image — same five actions, opposite permission, opposite result.
 */
class CrmBiPermissionIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private function actorWith(User $owner, array $permissions): User
    {
        $role = Role::create([
            'team_id' => $owner->current_team_id,
            'name' => 'Custom '.uniqid(),
            'slug' => 'custom-'.uniqid(),
        ]);
        $role->syncPermissions($permissions);
        $actor = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id, 'user_id' => $actor->id,
            'role_id' => $role->id, 'joined_at' => now(),
        ]);
        $actor->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $actor;
    }

    public function test_crm_manage_without_analytics_manage_writes_crm_but_not_bi(): void
    {
        $owner = User::factory()->create();
        $actor = $this->actorWith($owner, [PermissionCatalog::CRM_VIEW, PermissionCatalog::CRM_MANAGE]);
        $customer = Customer::factory()->create(['user_id' => $owner->id, 'team_id' => $owner->current_team_id]);
        $deal = Deal::factory()->create(['user_id' => $owner->id, 'customer_id' => $customer->id, 'team_id' => $owner->current_team_id]);
        $wonStage = CrmStage::defaultsFor($owner, $owner->current_team_id)->firstWhere('role', 'won');

        $this->actingAs($actor);

        // create a deal
        $this->post(route('crm.deals.store'), [
            'customer_id' => $customer->id, 'title' => 'New deal', 'amount' => 500,
        ])->assertRedirect();
        $this->assertDatabaseHas('deals', ['title' => 'New deal', 'team_id' => $owner->current_team_id]);

        // move the existing deal
        $this->patchJson(route('crm.deals.move', $deal), ['stage' => $wonStage->name])->assertOk();
        $this->assertSame($wonStage->id, $deal->fresh()->crm_stage_id);

        // add a task
        $this->postJson(route('crm.tasks.store'), ['title' => 'Call back', 'customer_id' => $customer->id])->assertCreated();
        $this->assertDatabaseHas('tasks', ['title' => 'Call back']);

        // add a note
        $this->postJson(route('crm.deals.notes.store', $deal), ['kind' => 'note', 'body' => 'Follow up'])->assertCreated();
        $this->assertDatabaseHas('crm_notes', ['body' => 'Follow up']);

        // edit the customer
        $this->patchJson(route('crm.customers.update', $customer), ['name' => 'Renamed'])->assertOk();
        $this->assertSame('Renamed', $customer->fresh()->name);

        // every BI write is forbidden
        $this->postJson(route('bi.dashboards.store'), ['name' => 'Revenue'])->assertForbidden();
        $this->postJson(route('bi.connections.store'), [
            'name' => 'Warehouse', 'engine' => 'mysql', 'host' => 'db.example.com',
            'port' => 3306, 'database' => 'shop', 'username' => 'readonly', 'password' => 'secret',
        ])->assertForbidden();
        $this->assertDatabaseCount('bi_dashboards', 0);
        $this->assertDatabaseCount('bi_connections', 0);
    }

    public function test_analytics_manage_without_crm_manage_writes_bi_but_not_crm(): void
    {
        $owner = User::factory()->create();
        $actor = $this->actorWith($owner, [PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE]);
        $customer = Customer::factory()->create(['user_id' => $owner->id, 'team_id' => $owner->current_team_id]);
        $deal = Deal::factory()->create(['user_id' => $owner->id, 'customer_id' => $customer->id, 'team_id' => $owner->current_team_id]);
        $wonStage = CrmStage::defaultsFor($owner, $owner->current_team_id)->firstWhere('role', 'won');

        $this->actingAs($actor);

        // the BI write that was crm.manage's mirror above now succeeds
        $this->postJson(route('bi.dashboards.store'), ['name' => 'Revenue'])
            ->assertRedirect();
        $this->assertDatabaseHas('bi_dashboards', ['name' => 'Revenue', 'team_id' => $owner->current_team_id]);

        // every CRM write from the other test is forbidden here
        $this->post(route('crm.deals.store'), [
            'customer_id' => $customer->id, 'title' => 'New deal', 'amount' => 500,
        ])->assertForbidden();
        $this->patchJson(route('crm.deals.move', $deal), ['stage' => $wonStage->name])->assertForbidden();
        $this->postJson(route('crm.tasks.store'), ['title' => 'Call back', 'customer_id' => $customer->id])->assertForbidden();
        $this->postJson(route('crm.deals.notes.store', $deal), ['kind' => 'note', 'body' => 'Follow up'])->assertForbidden();
        $this->patchJson(route('crm.customers.update', $customer), ['name' => 'Renamed'])->assertForbidden();

        $this->assertDatabaseMissing('deals', ['title' => 'New deal']);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('crm_notes', 0);
        $this->assertSame($customer->name, $customer->fresh()->name);
        $this->assertNotSame($wonStage->id, $deal->fresh()->crm_stage_id);
    }

    /**
     * Admin/Operator/Viewer's EFFECTIVE capabilities (not just their raw
     * permission list, already proven unchanged in PermissionCatalogTest)
     * must still behave exactly as they did before crm.* existed: a role
     * that could write CRM via analytics.manage must still be able to via
     * crm.manage, and a role that could only read must still only read.
     */
    public function test_admin_operator_and_viewer_effective_crm_access_is_unchanged(): void
    {
        $owner = User::factory()->create();
        $customer = Customer::factory()->create(['user_id' => $owner->id, 'team_id' => $owner->current_team_id]);

        $admin = $this->roleMember($owner, PermissionCatalog::ADMIN);
        $this->actingAs($admin)->post(route('crm.deals.store'), [
            'customer_id' => $customer->id, 'title' => 'Admin deal', 'amount' => 100,
        ])->assertRedirect();
        $this->assertDatabaseHas('deals', ['title' => 'Admin deal']);

        foreach ([PermissionCatalog::OPERATOR, PermissionCatalog::VIEWER] as $slug) {
            $member = $this->roleMember($owner, $slug);
            $this->actingAs($member)->get(route('crm.board'))->assertOk();
            $this->post(route('crm.deals.store'), [
                'customer_id' => $customer->id, 'title' => "{$slug} deal", 'amount' => 100,
            ])->assertForbidden();
            $this->assertDatabaseMissing('deals', ['title' => "{$slug} deal"]);
        }
    }

    private function roleMember(User $owner, string $slug): User
    {
        $member = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', $slug)->sole();
        TeamMembership::create([
            'team_id' => $owner->current_team_id, 'user_id' => $member->id,
            'role_id' => $role->id, 'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }
}
