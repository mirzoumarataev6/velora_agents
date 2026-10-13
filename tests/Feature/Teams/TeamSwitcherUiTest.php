<?php

namespace Tests\Feature\Teams;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 3a-3: the header dropdown (partials/team-switcher.blade.php) that
 * lists `User::teams()`. Separate from TeamSwitchTest (the controller's own
 * membership check) because this exercises the rendered page, not the
 * switch endpoint.
 */
class TeamSwitcherUiTest extends TestCase
{
    use RefreshDatabase;

    /** The dropdown must offer exactly this user's teams — never a stranger's. */
    public function test_the_dashboard_lists_exactly_the_teams_the_user_belongs_to(): void
    {
        $owner = User::factory()->create(['name' => 'Olga']);
        $teamA = Team::find($owner->current_team_id);
        $teamA->forceFill(['name' => 'Olga Team One'])->save();
        $teamB = app(CreatesPersonalTeam::class)->create($owner);
        $teamB->forceFill(['name' => 'Olga Team Two'])->save();

        // A completely unrelated team/user — must never appear.
        $stranger = User::factory()->create(['name' => 'Stranger']);
        Team::find($stranger->current_team_id)->forceFill(['name' => 'Strangers Team'])->save();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Olga Team One');
        $response->assertSee('Olga Team Two');
        $response->assertDontSee('Strangers Team');
    }
}
