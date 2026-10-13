<?php

namespace Tests\Feature\Teams;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 3a: read-only groundwork for multi-team accounts. The switcher
 * itself changes nothing about what a user is ALLOWED to do once
 * switched — only `TeamSwitchController`'s own membership check, proven
 * here against a team the user does and does not belong to.
 */
class TeamSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_to_a_team_the_user_belongs_to_persists_current_team_id(): void
    {
        $owner = User::factory()->create();
        $teamA = $owner->current_team_id;
        $teamB = app(CreatesPersonalTeam::class)->create($owner);
        $owner->forceFill(['current_team_id' => $teamA])->save();

        $this->actingAs($owner)
            ->post(route('teams.switch'), ['team_id' => $teamB->id])
            ->assertRedirect();

        $this->assertSame($teamB->id, $owner->fresh()->current_team_id);
    }

    public function test_switching_to_a_team_the_user_is_not_a_member_of_is_rejected(): void
    {
        $owner = User::factory()->create();
        $originalTeamId = $owner->current_team_id;
        $otherTeam = Team::factory()->create();

        $this->actingAs($owner)
            ->post(route('teams.switch'), ['team_id' => $otherTeam->id])
            ->assertForbidden();

        $this->assertSame($originalTeamId, $owner->fresh()->current_team_id, 'A non-member team_id must never be persisted.');
    }

    /** A posted id that doesn't even name a real team must fail the same way — not a 500 on a missing team row. */
    public function test_switching_to_a_nonexistent_team_id_is_rejected(): void
    {
        $owner = User::factory()->create();
        $originalTeamId = $owner->current_team_id;

        $this->actingAs($owner)
            ->post(route('teams.switch'), ['team_id' => 999999])
            ->assertForbidden();

        $this->assertSame($originalTeamId, $owner->fresh()->current_team_id);
    }

    public function test_switching_requires_a_signed_in_user(): void
    {
        $team = Team::factory()->create();

        $this->post(route('teams.switch'), ['team_id' => $team->id])
            ->assertRedirect(route('login'));
    }
}
