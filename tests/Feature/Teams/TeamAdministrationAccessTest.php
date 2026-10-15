<?php

namespace Tests\Feature\Teams;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog as P;
use App\Services\Teams\CreatesTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamAdministrationAccessTest extends TestCase
{
    use RefreshDatabase;

    private function member(User $owner, string $slug): User
    {
        $member = User::factory()->create();
        TeamMembership::create(['team_id' => $owner->current_team_id, 'user_id' => $member->id,
            'role_id' => $this->role($owner, $slug)->id, 'joined_at' => now()]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        return $member;
    }

    private function role(User $owner, string $slug): Role
    {
        return Role::where('team_id', $owner->current_team_id)->where('slug', $slug)->sole();
    }

    public function test_admin_can_invite_assign_remove_and_manage_ordinary_roles(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $admin = $this->member($owner, 'admin');
        $member = $this->member($owner, 'operator');
        $membership = $member->membershipIn($owner->current_team_id);
        $viewer = $this->role($owner, 'viewer');
        $this->actingAs($admin)->get(route('teams.index'))->assertOk()->assertSee(route('teams.roles.index'));
        $this->get(route('teams.roles.index'))->assertOk()->assertSee('id="team-role-form"', false);
        $this->post(route('teams.roles.store'), ['name' => 'Assistant', 'permissions' => [P::AGENTS_VIEW]])->assertRedirect();
        $custom = Role::where('name', 'Assistant')->sole();
        $this->patch(route('teams.roles.update', $custom), ['name' => 'Assistant', 'permissions' => [P::CATALOG_VIEW]])->assertRedirect();
        $this->assertSame([P::CATALOG_VIEW], $custom->permissionKeys());
        $this->post(route('teams.invite'), ['email' => 'invitee@example.com', 'role_id' => $viewer->id])->assertRedirect();
        $this->assertDatabaseHas('invitations', ['invited_by' => $admin->id, 'role_id' => $viewer->id]);
        $this->delete(route('teams.invitations.destroy', Invitation::sole()))->assertRedirect();
        $this->patch(route('teams.members.update', $membership), ['role_id' => $viewer->id])->assertRedirect();
        $this->assertSame($viewer->id, $membership->fresh()->role_id);
        $this->delete(route('teams.members.destroy', $membership))->assertRedirect();
        $this->assertDatabaseMissing('team_user', ['id' => $membership->id]);
    }

    public function test_admin_cannot_grant_owner_or_permissions_they_lack(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $admin = $this->member($owner, 'admin');
        $viewer = $this->member($owner, 'viewer');
        $ownerRole = $this->role($owner, 'owner');
        $powerful = Role::create(['team_id' => $owner->current_team_id, 'slug' => 'billing-specialist', 'name' => 'Billing specialist']);
        $powerful->syncPermissions([P::BILLING_MANAGE]);
        $this->actingAs($admin);
        foreach ([$ownerRole, $powerful] as $role) {
            $this->post(route('teams.invite'), ['email' => 'escalation@example.com', 'role_id' => $role->id])->assertForbidden();
            $this->patch(route('teams.members.update', $viewer->membershipIn($owner->current_team_id)), ['role_id' => $role->id])->assertForbidden();
        }
        $this->post(route('teams.roles.store'), ['name' => 'Escalated', 'permissions' => [P::BILLING_VIEW, P::BILLING_MANAGE]])->assertForbidden();
        $this->patch(route('teams.roles.update', $this->role($owner, 'admin')), ['permissions' => [P::TEAM_DELETE]])->assertForbidden();
        $this->patch(route('teams.roles.update', $powerful), ['name' => 'Changed', 'permissions' => []])->assertForbidden();
        $this->delete(route('teams.members.destroy', $owner->membershipIn($owner->current_team_id)))->assertForbidden();
        $this->assertDatabaseCount('invitations', 0);
        $this->assertSame([P::BILLING_MANAGE], $powerful->permissionKeys());
        $this->assertFalse($admin->fresh()->hasPermissionOn($owner->current_team_id, P::TEAM_DELETE));
        Mail::assertNothingSent();
    }

    public function test_viewer_can_read_members_and_roles_without_management_forms(): void
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $viewer = $this->member($owner, 'viewer');
        $this->actingAs($viewer)->get(route('teams.index'))->assertOk()->assertSee($owner->email)
            ->assertDontSee('id="team-invite-email"', false)->assertDontSee('name="role_id"', false);
        $this->get(route('teams.roles.index', ['edit' => $this->role($owner, 'admin')->id]))->assertOk()
            ->assertDontSee('id="team-role-form"', false)->assertSee('disabled', false);
    }

    public function test_self_role_change_invalidates_the_acting_users_cached_permissions(): void
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $admin = $this->member($owner, 'admin');
        $this->assertTrue($admin->hasPermissionOn($owner->current_team_id, P::TEAM_ROLES_MANAGE));
        $this->actingAs($admin)->patch(route('teams.members.update', $admin->membershipIn($owner->current_team_id)), ['role_id' => $this->role($owner, 'viewer')->id])->assertRedirect();
        $this->assertFalse($admin->hasPermissionOn($owner->current_team_id, P::TEAM_ROLES_MANAGE));
        $this->post(route('teams.roles.store'), ['name' => 'No longer admin'])->assertForbidden();
    }
}
