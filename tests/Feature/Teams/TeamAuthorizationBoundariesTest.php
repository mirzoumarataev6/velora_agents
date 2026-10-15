<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Message;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAuthorizationBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private function member(User $owner, array $permissions): User
    {
        $member = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', 'viewer')->sole();
        $role->syncPermissions($permissions);
        TeamMembership::create(['team_id' => $owner->current_team_id, 'user_id' => $member->id, 'role_id' => $role->id, 'joined_at' => now()]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    public function test_board_render_rejects_a_foreign_dataset_even_when_the_actor_is_a_member_of_both_teams(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, ['analytics.view']);
        $board = BiDashboard::factory()->create(['user_id' => $owner->id]);
        $personalTeam = $member->teams()->where('teams.id', '!=', $owner->current_team_id)->sole();
        $dataset = BiDataset::factory()->create(['user_id' => $member->id, 'team_id' => $personalTeam->id]);
        $widget = BiWidget::factory()->create(['user_id' => $owner->id, 'bi_dashboard_id' => $board->id, 'bi_dataset_id' => $dataset->id]);
        $this->actingAs($member)->getJson(route('bi.dashboards.show', $board))->assertForbidden();
        $this->getJson(route('bi.widgets.data', [$board, $widget]))->assertForbidden();
    }

    public function test_crm_view_does_not_bypass_a_revoked_conversation_permission(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, ['crm.view']);
        $customer = Customer::factory()->create(['user_id' => $owner->id]);
        $agent = Agent::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create(['agent_id' => $agent->id, 'customer_id' => $customer->id]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'content' => 'Private conversation content']);
        $deal = Deal::factory()->create(['user_id' => $owner->id, 'customer_id' => $customer->id]);
        $this->actingAs($member)->get(route('crm.customers.show', $customer))->assertOk()->assertDontSee('Private conversation content');
        $this->getJson(route('crm.deals.activity', $deal))->assertOk()->assertDontSee('Private conversation content');
    }

    public function test_photo_upload_authorizes_catalog_permission_before_validation(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $owner->id]);
        $catalogMember = $this->member($owner, ['catalog.view', 'catalog.manage']);
        $this->actingAs($catalogMember)->postJson(route('agents.catalog.products.images.store', [$agent, 'product']), [])->assertUnprocessable();
        $catalogMember->membershipIn($owner->current_team_id)->role->syncPermissions(['agents.view', 'agents.update']);
        $catalogMember->flushPermissionCache();
        $this->postJson(route('agents.catalog.products.images.store', [$agent, 'product']), [])->assertForbidden();
    }

    public function test_navigation_hides_unavailable_sections_and_disables_their_polling(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, ['agents.view']);
        $response = $this->actingAs($member)->get(route('agents.index'))->assertOk();
        $response->assertDontSee('data-nav="crm"', false)->assertDontSee('data-nav="conversations"', false)
            ->assertDontSee('data-nav="bi"', false)->assertDontSee('data-nav="dashboard"', false);
        $response->assertSee("pollUrl: ''", false);
    }
}
