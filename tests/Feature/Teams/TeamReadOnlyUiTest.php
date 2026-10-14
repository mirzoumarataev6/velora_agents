<?php

namespace Tests\Feature\Teams;

use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamReadOnlyUiTest extends TestCase
{
    use RefreshDatabase;

    private function customRole(User $owner, array $permissions): User
    {
        $role = Role::create([
            'team_id' => $owner->current_team_id,
            'name' => 'Custom '.uniqid(),
            'slug' => 'custom-'.uniqid(),
        ]);
        $role->syncPermissions($permissions);
        $member = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id, 'user_id' => $member->id,
            'role_id' => $role->id, 'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    /**
     * B4: crm.view and analytics.view must gate /crm and /bi independently
     * of each other, both at the route and in the nav each page renders
     * (both extend layouts.dashboard, so either page's own markup proves
     * both nav items at once without needing /dashboard itself — which
     * requires analytics.view and so isn't reachable by the crm-only actor).
     */
    public function test_crm_view_alone_opens_crm_not_bi_and_analytics_view_alone_is_the_mirror(): void
    {
        $owner = User::factory()->create();
        $crmOnly = $this->customRole($owner, [PermissionCatalog::CRM_VIEW]);
        $analyticsOnly = $this->customRole($owner, [PermissionCatalog::ANALYTICS_VIEW]);

        $this->actingAs($crmOnly)->get(route('crm.board'))->assertOk()
            ->assertSee('data-nav="crm"', false)->assertDontSee('data-nav="bi"', false);
        $this->get(route('bi.dashboards.index'))->assertForbidden();

        $this->actingAs($analyticsOnly)->get(route('bi.dashboards.index'))->assertOk()
            ->assertDontSee('data-nav="crm"', false)->assertSee('data-nav="bi"', false);
        $this->get(route('crm.board'))->assertForbidden();
    }

    public function test_viewer_can_read_crm_and_bi_without_write_controls(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', 'viewer')->sole();
        TeamMembership::create(['team_id' => $owner->current_team_id, 'user_id' => $viewer->id, 'role_id' => $role->id, 'joined_at' => now()]);
        $viewer->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $dashboard = BiDashboard::factory()->create(['user_id' => $owner->id]);
        $dataset = BiDataset::factory()->create(['user_id' => $owner->id]);
        BiWidget::factory()->create([
            'user_id' => $owner->id,
            'bi_dashboard_id' => $dashboard->id,
            'bi_dataset_id' => $dataset->id,
        ]);

        $this->actingAs($viewer)->get(route('crm.stages.index'))->assertOk()
            ->assertDontSee(route('crm.stages.store'))->assertDontSee(route('crm.stages.reorder'));
        $this->get(route('crm.board'))->assertOk()
            ->assertDontSee('@click="adding = true"', false)
            ->assertDontSee('x-data="dealCreateForm', false)
            ->assertDontSee('@click="createWorkspace()"', false)
            ->assertDontSee('@click="addNote()"', false)
            ->assertDontSee('@click="destroy(selected)"', false)
            ->assertSee('@click="activityOpen = !activityOpen"', false)
            ->assertSee('<fieldset disabled>', false);
        $this->get(route('bi.dashboards.show', $dashboard))->assertOk()
            ->assertDontSee('data-qa="chat-toggle"', false)
            ->assertDontSee('@click="newWidget()"', false)
            ->assertDontSee('@click="editWidget(', false)
            ->assertSee('widget-card', false)
            ->assertSee('canManage: false', false);
    }
}
