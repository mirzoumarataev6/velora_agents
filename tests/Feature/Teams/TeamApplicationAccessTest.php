<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamApplicationAccessTest extends TestCase
{
    use RefreshDatabase;

    private function member(User $owner, string $slug = 'viewer', ?array $permissions = null): User
    {
        $member = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', $slug)->sole();
        if ($permissions !== null) {
            $role->syncPermissions($permissions);
        }
        TeamMembership::create(['team_id' => $owner->current_team_id, 'user_id' => $member->id, 'role_id' => $role->id, 'joined_at' => now()]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    public function test_shared_lists_use_active_team_and_fail_closed_without_membership(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner);
        $agent = Agent::factory()->create(['user_id' => $owner->id, 'name' => 'Shared agent']);
        $other = Agent::factory()->create(['user_id' => $owner->id, 'team_id' => $member->teams()->where('teams.id', '!=', $owner->current_team_id)->sole()->id]);
        $channel = Channel::factory()->create(['agent_id' => $agent->id]);
        $conversation = Conversation::factory()->create(['agent_id' => $agent->id, 'channel_id' => $channel->id]);
        $lead = Lead::factory()->create(['agent_id' => $agent->id, 'conversation_id' => $conversation->id]);
        $board = BiDashboard::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($member)->get(route('agents.index'))->assertOk()->assertSee('Shared agent')->assertDontSee($other->name);
        $this->getJson(route('conversations.list'))->assertOk()->assertJsonPath('data.0.id', $conversation->id);
        $this->get(route('leads.index'))->assertOk()->assertSee($lead->customer_name);
        $this->get(route('bi.dashboards.index'))->assertOk()->assertSee($board->name);
        foreach ([Agent::class, Conversation::class, Lead::class, BiDashboard::class] as $model) {
            $this->assertSame(1, $model::ownedBy($member)->count());
            $member->forceFill(['current_team_id' => null])->save();
            $this->assertSame(0, $model::ownedBy($member)->count());
            $member->forceFill(['current_team_id' => $owner->current_team_id])->save();
        }
    }

    public function test_revoked_permissions_deny_non_crm_lists(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, permissions: []);
        foreach (['agents.index', 'conversations.index', 'conversations.list', 'leads.index', 'leads.poll', 'dashboard'] as $route) {
            $this->actingAs($member)->getJson(route($route))->assertForbidden();
        }
    }

    public function test_agent_edit_does_not_grant_catalog_channels_or_conversation_data(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, permissions: ['agents.view', 'agents.update']);
        $agent = Agent::factory()->create(['user_id' => $owner->id]);
        Channel::factory()->create(['agent_id' => $agent->id, 'type' => 'whatsapp', 'credentials' => ['verify_token' => 'hidden-channel-secret']]);
        $this->actingAs($member)->getJson(route('agents.catalog.show', $agent))->assertForbidden();
        $this->deleteJson(route('agents.catalog.destroy', $agent))->assertForbidden();
        $this->postJson(route('agents.channels.telegram.connect', $agent), [])->assertForbidden();
        $this->get(route('agents.show', $agent))->assertOk()->assertDontSee('hidden-channel-secret')
            ->assertDontSee('agents.partials.conversations-tab');
    }

    public function test_catalog_permission_works_without_agent_update_and_reply_is_distinct_from_takeover(): void
    {
        $owner = User::factory()->create();
        $member = $this->member($owner, permissions: ['catalog.view', 'catalog.manage', 'conversations.view', 'conversations.reply']);
        $agent = Agent::factory()->create(['user_id' => $owner->id]);
        $conversation = Conversation::factory()->create(['agent_id' => $agent->id, 'channel_id' => null]);
        $this->actingAs($member)->getJson(route('agents.catalog.show', $agent))->assertOk();
        $this->postJson(route('agents.catalog.cancel', $agent))->assertOk();
        $this->patchJson(route('agents.status', $agent), ['status' => 'paused'])->assertForbidden();
        // Missing content reaches validation only when the reply permission is accepted.
        $this->postJson(route('conversations.reply', $conversation), [])->assertUnprocessable();
        $this->patchJson(route('conversations.status', $conversation), [])->assertForbidden();
    }

    public function test_viewer_cannot_mutate_via_bi_analyst_or_see_channel_secrets(): void
    {
        $owner = User::factory()->create();
        $viewer = $this->member($owner);
        $board = BiDashboard::factory()->create(['user_id' => $owner->id]);
        $agent = Agent::factory()->create(['user_id' => $owner->id]);
        Channel::factory()->create(['agent_id' => $agent->id, 'type' => 'whatsapp', 'credentials' => ['verify_token' => 'secret-verify-token']]);
        $this->actingAs($viewer)->postJson(route('bi.chat.store', $board), [])->assertForbidden();
        $this->postJson(route('bi.widgets.store', $board), [])->assertForbidden();
        $this->get(route('agents.show', $agent))->assertOk()->assertDontSee('secret-verify-token')->assertDontSee('id="agent-settings-form"');
    }

    public function test_bi_widget_links_are_limited_to_dashboard_team_after_switch(): void
    {
        $owner = User::factory()->create();
        $admin = $this->member($owner, 'admin');
        $shared = BiDashboard::factory()->create(['user_id' => $owner->id]);
        $personal = $admin->teams()->where('teams.id', '!=', $owner->current_team_id)->sole();
        $admin->forceFill(['current_team_id' => $personal->id])->save();
        $source = BiSource::factory()->create(['user_id' => $admin->id]);
        $dataset = BiDataset::factory()->create(['user_id' => $admin->id, 'bi_source_id' => $source->id]);
        $this->actingAs($admin)->postJson(route('bi.widgets.store', $shared), ['bi_dataset_id' => $dataset->id, 'title' => 'Cross-team', 'type' => 'kpi', 'spec' => ['measures' => [['aggregate' => 'count']]]])->assertUnprocessable()->assertJsonValidationErrors('bi_dataset_id');
        $this->assertDatabaseCount('bi_widgets', 0);
    }
}
