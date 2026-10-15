<?php

namespace Tests\Feature\Teams;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * HomeDestination replaces the old unconditional "/home -> /dashboard"
 * redirect, which 403'd any role without analytics.view the instant it
 * signed in. Exercised here through every door that used to hardcode
 * /dashboard: login, invitation accept, and (the one that can leave a
 * user mid-session on a page their new role can't open) a team switch.
 */
class HomeDestinationTest extends TestCase
{
    use RefreshDatabase;

    private function customRole(User $owner, array $permissions): Role
    {
        $role = Role::create([
            'team_id' => $owner->current_team_id,
            'name' => 'Custom '.uniqid(),
            'slug' => 'custom-'.uniqid(),
        ]);
        $role->syncPermissions($permissions);

        return $role;
    }

    private function addMember(User $owner, User $member, Role $role): void
    {
        TeamMembership::create([
            'team_id' => $owner->current_team_id, 'user_id' => $member->id,
            'role_id' => $role->id, 'joined_at' => now(),
        ]);
    }

    public function test_a_crm_view_only_role_logs_in_and_lands_on_crm(): void
    {
        $owner = User::factory()->create();
        $role = $this->customRole($owner, [PermissionCatalog::CRM_VIEW]);
        $member = User::factory()->create(['email' => 'crm-member@example.test', 'password' => Hash::make('password123')]);
        $this->addMember($owner, $member, $role);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->post('/login', ['email' => 'crm-member@example.test', 'password' => 'password123'])
            ->assertRedirect(route('crm.board'));

        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_the_same_role_accepts_an_invitation_and_lands_on_crm(): void
    {
        $owner = User::factory()->create();
        $role = $this->customRole($owner, [PermissionCatalog::CRM_VIEW]);
        $invitee = User::factory()->create(['email' => 'invitee@example.test']);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id, 'role_id' => $role->id,
            'email' => 'invitee@example.test', 'invited_by' => $owner->id,
        ]);

        $this->actingAs($invitee)->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('crm.board'));

        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_a_role_with_no_view_permissions_gets_the_no_access_page(): void
    {
        $owner = User::factory()->create();
        $role = $this->customRole($owner, [PermissionCatalog::BILLING_VIEW]);
        $member = User::factory()->create();
        $this->addMember($owner, $member, $role);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($member)->get(route('home'))->assertRedirect(route('home.no-access'));

        $response = $this->get(route('home.no-access'));
        $response->assertOk();
        $response->assertSee(__('common.no_access.title'));
        $response->assertSee(__('common.no_access.message'));
    }

    public function test_owner_still_lands_on_dashboard(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test', 'password' => Hash::make('password123')]);

        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
    }

    /**
     * The scenario back() alone cannot handle: the actor was on /dashboard
     * under team A (where their role has analytics.view) and switches into
     * team B, where their role only has crm.view. A plain back() would
     * return them to /dashboard and 403 immediately; this must not happen.
     */
    public function test_switching_into_a_team_where_the_current_page_is_forbidden_does_not_end_on_a_403(): void
    {
        $teamAOwner = User::factory()->create();
        $teamBOwner = User::factory()->create();
        $actor = User::factory()->create();

        $roleA = $this->customRole($teamAOwner, [PermissionCatalog::ANALYTICS_VIEW]);
        $this->addMember($teamAOwner, $actor, $roleA);
        $actor->forceFill(['current_team_id' => $teamAOwner->current_team_id])->save();

        $roleB = $this->customRole($teamBOwner, [PermissionCatalog::CRM_VIEW]);
        $this->addMember($teamBOwner, $actor, $roleB);

        $this->actingAs($actor)->get(route('dashboard'))->assertOk();

        $response = $this->from(route('dashboard'))
            ->post(route('teams.switch'), ['team_id' => $teamBOwner->current_team_id]);

        $response->assertRedirect(route('crm.board'));
        $this->get(route('crm.board'))->assertOk();
        $this->assertSame($teamBOwner->current_team_id, $actor->fresh()->current_team_id);
    }

    /**
     * The mirror of the test above: switching into a team where the
     * CURRENT page is still open must keep the ordinary back() behavior
     * (the switcher dropdown lives on every page, so this is the common
     * case and must not be disturbed by the new check).
     */
    public function test_switching_into_a_team_where_the_current_page_is_still_open_uses_a_plain_back(): void
    {
        $teamAOwner = User::factory()->create();
        $teamBOwner = User::factory()->create();
        $actor = User::factory()->create();

        $roleA = $this->customRole($teamAOwner, [PermissionCatalog::ANALYTICS_VIEW]);
        $this->addMember($teamAOwner, $actor, $roleA);
        $actor->forceFill(['current_team_id' => $teamAOwner->current_team_id])->save();

        $roleB = $this->customRole($teamBOwner, [PermissionCatalog::ANALYTICS_VIEW]);
        $this->addMember($teamBOwner, $actor, $roleB);

        $response = $this->actingAs($actor)->from(route('dashboard'))
            ->post(route('teams.switch'), ['team_id' => $teamBOwner->current_team_id]);

        $response->assertRedirect(route('dashboard'));
    }

    /**
     * Tried to break: a guest reaching either new route directly. Both sit
     * inside the auth+auth.session group, same as /dashboard always has —
     * confirming that placement rather than assuming it.
     */
    public function test_a_guest_is_sent_to_login_not_the_no_access_page(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('home.no-access'))->assertRedirect(route('login'));
    }

    /**
     * Tried to break: switching into a team the caller is NOT a member of.
     * The pre-existing membershipIn() check must still run before anything
     * HomeDestination-related, unchanged by this stage.
     */
    public function test_switching_into_a_team_with_no_membership_is_still_forbidden(): void
    {
        $actor = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($actor)->from(route('dashboard'))
            ->post(route('teams.switch'), ['team_id' => $stranger->current_team_id])
            ->assertForbidden();

        $this->assertNotSame($stranger->current_team_id, $actor->fresh()->current_team_id);
    }
}
