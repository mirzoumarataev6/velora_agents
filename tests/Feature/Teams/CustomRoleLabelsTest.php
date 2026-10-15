<?php

namespace Tests\Feature\Teams;

use App\Mail\TeamInvitationMail;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Teams\CreatesTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomRoleLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_role_name_is_shown_in_the_team_section_and_the_invitation_email(): void
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $role = Role::create(['team_id' => $owner->current_team_id, 'name' => 'Sales coach', 'slug' => 'sales-coach-custom']);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'coach@example.com',
        ]);

        $response = $this->actingAs($owner)->get(route('teams.index'));

        $response->assertOk();
        $response->assertSee('Sales coach');
        $response->assertDontSee('agents.nav.teams.roles.sales-coach-custom');
        $this->assertSame('Sales coach', (new TeamInvitationMail($invitation))->content()->with['roleName']);
    }

    public function test_team_switcher_uses_custom_role_name_for_a_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $role = Role::create(['team_id' => $owner->current_team_id, 'name' => 'Sales coach', 'slug' => 'sales-coach-custom']);
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $response = $this->actingAs($member)->get(route('settings.index'));

        $response->assertOk();
        $response->assertSee('Sales coach');
        $response->assertDontSee('agents.nav.teams.roles.sales-coach-custom');
    }

    public function test_created_custom_role_can_be_assigned_to_a_member_and_an_invitation(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');
        $member = User::factory()->create();
        $operator = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::OPERATOR)->sole();
        $membership = TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => $operator->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($owner)->post(route('teams.roles.store'), [
            'name' => 'Billing specialist',
            'permissions' => [PermissionCatalog::BILLING_VIEW, PermissionCatalog::BILLING_MANAGE],
        ])->assertRedirect();
        $custom = Role::where('team_id', $owner->current_team_id)->where('name', 'Billing specialist')->sole();

        $this->patch(route('teams.members.update', $membership), ['role_id' => $custom->id])->assertRedirect();
        $this->post(route('teams.invite'), ['email' => 'specialist@example.com', 'role_id' => $custom->id])->assertRedirect();

        $this->assertSame($custom->id, $membership->fresh()->role_id);
        $this->assertSame($custom->id, Invitation::where('email', 'specialist@example.com')->sole()->role_id);
        $member->flushPermissionCache();
        $this->assertTrue($member->hasPermissionOn($owner->current_team_id, PermissionCatalog::BILLING_MANAGE));
        $this->assertFalse($member->hasPermissionOn($owner->current_team_id, PermissionCatalog::AGENTS_DELETE));
    }
}
