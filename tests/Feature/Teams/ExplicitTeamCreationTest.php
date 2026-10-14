<?php

namespace Tests\Feature\Teams;

use App\Models\Agent;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplicitTeamCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_solo_user_can_explicitly_create_a_named_team(): void
    {
        $user = User::factory()->create();
        $personalTeamId = $user->current_team_id;

        $this->actingAs($user)->post(route('teams.store'), ['name' => 'Sales'])
            ->assertRedirect(route('teams.index'));

        $team = Team::where('name', 'Sales')->sole();
        $this->assertSame($user->id, $team->created_by);
        $this->assertSame($team->id, $user->fresh()->current_team_id);
        $this->assertSame($personalTeamId, $team->id);
        $this->assertFalse($team->is_personal);
        $this->assertTrue(TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->firstOrFail()->role->is_owner);
        $this->assertSame(4, Role::where('team_id', $team->id)->count());
        $this->assertSame(PermissionCatalog::defaultMatrix()[PermissionCatalog::OPERATOR],
            Role::where('team_id', $team->id)->where('slug', PermissionCatalog::OPERATOR)->firstOrFail()->permissionKeys());
    }

    public function test_promotion_keeps_personal_data_and_new_members_see_only_the_resources_their_role_allows(): void
    {
        $owner = User::factory()->create();
        $teamId = $owner->current_team_id;
        $agent = Agent::factory()->create(['user_id' => $owner->id]);
        $customer = Customer::factory()->create(['user_id' => $owner->id]);
        $member = User::factory()->create();

        $this->actingAs($owner)->get(route('teams.index'))->assertOk()
            ->assertSee(route('teams.store'))
            ->assertSee(__('settings.team.personal_badge'))
            ->assertDontSee("{$owner->name}'s Team")
            ->assertDontSee('id="team-invite-email"', false)
            ->assertDontSee(route('teams.roles.index'));

        $this->post(route('teams.store'), ['name' => 'Sales'])->assertRedirect(route('teams.index'));
        $this->assertSame($teamId, $agent->fresh()->team_id);
        $this->assertSame($teamId, $customer->fresh()->team_id);

        $role = Role::where('team_id', $teamId)->where('slug', PermissionCatalog::VIEWER)->sole();
        TeamMembership::create(['team_id' => $teamId, 'user_id' => $member->id, 'role_id' => $role->id, 'joined_at' => now()]);
        $member->forceFill(['current_team_id' => $teamId])->save();

        $this->assertTrue($member->can('view', $agent));
        $this->assertTrue($member->can('view', $customer));
        $this->assertFalse($member->can('update', $agent));
        $this->assertFalse($member->can('update', $customer));

        // The same shared row must disappear from authorization as soon as
        // the assigned role loses that permission; team membership alone
        // never grants access to the owner's agents.
        $role->syncPermissions([PermissionCatalog::ANALYTICS_VIEW]);
        $member->flushPermissionCache();
        $this->assertFalse($member->can('view', $agent));
        $this->assertTrue($member->can('view', $customer));

        $this->actingAs($owner)->get(route('teams.index'))->assertOk()
            ->assertSee('Sales')
            ->assertSee('id="team-invite-email"', false)
            ->assertSee(route('teams.roles.index'));
    }

    public function test_guests_cannot_create_a_team_and_blank_names_are_rejected(): void
    {
        $this->post(route('teams.store'), ['name' => 'Sales'])->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)->post(route('teams.store'), ['name' => '  '])
            ->assertSessionHasErrors('name', null, 'createTeam');
        $this->assertSame(1, Team::count());
    }

    public function test_a_personal_workspace_cannot_invite_people_or_edit_team_roles_before_it_is_named(): void
    {
        $owner = User::factory()->create();
        $operator = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::OPERATOR)->sole();

        $this->actingAs($owner)->post(route('teams.invite'), [
            'email' => 'member@example.com', 'role_id' => $operator->id,
        ])->assertForbidden();
        $this->get(route('teams.roles.index'))->assertForbidden();
        $this->post(route('teams.roles.store'), ['name' => 'Specialist'])->assertForbidden();

        $this->assertDatabaseCount('invitations', 0);
        $this->assertDatabaseMissing('roles', ['name' => 'Specialist']);
    }
}
