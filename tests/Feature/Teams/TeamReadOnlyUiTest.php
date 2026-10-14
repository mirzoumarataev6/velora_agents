<?php

namespace Tests\Feature\Teams;

use App\Models\BiDashboard;
use App\Models\BiDataset;
use App\Models\BiWidget;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamReadOnlyUiTest extends TestCase
{
    use RefreshDatabase;

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
