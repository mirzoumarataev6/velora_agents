<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $agent = Agent::factory()->create();

        $this->get(route('agents.show', $agent))->assertRedirect(route('login'));
    }

    public function test_owner_can_view_their_agent(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee($agent->name);
    }

    public function test_other_user_cannot_view_agent(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->get(route('agents.show', $agent))
            ->assertForbidden();
    }

    public function test_other_user_cannot_update_agent(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->put(route('agents.update', $agent), [
                'name' => 'Hijacked',
                'language' => 'auto',
                'tone' => 'friendly',
                'on_not_found' => 'apologize',
            ])
            ->assertForbidden();

        $this->assertNotEquals('Hijacked', $agent->fresh()->name);
    }

    public function test_other_user_cannot_delete_agent(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->delete(route('agents.destroy', $agent))
            ->assertForbidden();

        $this->assertModelExists($agent);
    }

    public function test_owner_only_sees_their_own_agents_in_index(): void
    {
        $user = User::factory()->create();
        $ownAgent = Agent::factory()->for($user)->create();
        $otherAgent = Agent::factory()->create();

        $this->actingAs($user)
            ->get(route('agents.index'))
            ->assertOk()
            ->assertSee($ownAgent->name)
            ->assertDontSee($otherAgent->name);
    }
}
