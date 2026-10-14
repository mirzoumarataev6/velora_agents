<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalTeamModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_still_gets_a_private_tenant_namespace_without_an_explicit_team(): void
    {
        $user = User::factory()->create();
        $team = Team::findOrFail($user->current_team_id);
        $agent = Agent::factory()->for($user)->create();

        $this->assertTrue($team->is_personal);
        $this->assertSame($user->id, $team->created_by);
        $this->assertSame($team->id, $agent->team_id);
        $this->assertSame(1, TeamMembership::where('team_id', $team->id)->count());
        $this->assertSame(4, Role::where('team_id', $team->id)->count());

    }
}
