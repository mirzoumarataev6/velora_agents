<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H4: /dashboard stays gated by analytics.view alone (DashboardController is
 * unchanged on that front), but the lead figures it has always shown
 * (leads_week, the funnel's lead/confirmed stages, leads-by-status, pending
 * orders/price approvals, per-agent lead counts) belong to crm.view —
 * LeadPolicy already keys off it. A role holding only analytics.view must
 * see none of them, and DashboardMetrics must not even query the leads
 * table to produce a figure it is about to hide (see its own canViewCrm
 * docblock for why that is passed in rather than checked with Gate inside
 * the service).
 */
class DashboardCrmGatingTest extends TestCase
{
    use RefreshDatabase;

    private function roleWith(User $owner, array $permissions): User
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

    public function test_an_analytics_view_only_role_sees_no_lead_figures_and_runs_no_lead_query(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        Conversation::factory()->for($agent)->create();
        Lead::factory()->for($agent)->create();
        Lead::factory()->for($agent)->create(['status' => 'in_progress']);

        $viewer = $this->roleWith($owner, [PermissionCatalog::ANALYTICS_VIEW]);

        DB::enableQueryLog();
        $response = $this->actingAs($viewer)->get(route('dashboard'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertDontSee(__('dashboard.summary.leads_week'));
        $response->assertDontSee(__('dashboard.funnel.title'));
        $response->assertDontSee(__('dashboard.leads_status.title'));
        $response->assertDontSee(__('dashboard.pending.title'));

        $leadQueries = array_values(array_filter(
            $queries,
            fn ($q) => preg_match('/from\s+["`]?leads["`]?/i', $q['query']) === 1,
        ));
        $this->assertSame(
            [],
            $leadQueries,
            'A query against the leads table ran for a role without crm.view: '.json_encode($leadQueries),
        );
    }

    public function test_a_role_with_both_analytics_and_crm_view_still_sees_lead_figures(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        Conversation::factory()->for($agent)->create();
        Lead::factory()->for($agent)->create();

        $viewer = $this->roleWith($owner, [PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::CRM_VIEW]);

        $response = $this->actingAs($viewer)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(__('dashboard.summary.leads_week'));
        $response->assertSee(__('dashboard.funnel.title'));
        $response->assertSee(__('dashboard.leads_status.title'));
        $response->assertSee(__('dashboard.pending.title'));
    }
}
