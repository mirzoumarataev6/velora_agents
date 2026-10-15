<?php

namespace Tests\Feature\Teams;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Projects\ProjectBoards;
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
     * D-04: an unauthenticated GET to /dashboard (protected by the `auth`
     * middleware) stores its own URL as `url.intended` via Laravel's own
     * `redirect()->guest()` — before this role ever gets a chance to sign
     * in. `redirect()->intended()` prefers that stored value over whatever
     * default it is passed, so without HomeDestination::redirectIntended()
     * stripping it, a crm.view-only role would log in only to be bounced
     * straight back to the one page it cannot open.
     */
    public function test_a_stored_dashboard_intent_does_not_override_the_safe_landing_for_a_role_without_it(): void
    {
        $owner = User::factory()->create();
        $role = $this->customRole($owner, [PermissionCatalog::CRM_VIEW]);
        $member = User::factory()->create(['email' => 'crm-intended@example.test', 'password' => Hash::make('password123')]);
        $this->addMember($owner, $member, $role);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        // Triggers Laravel's own redirect()->guest(), which stores THIS
        // URL as url.intended before sending the guest to /login.
        $this->get(route('dashboard'));

        $this->post('/login', ['email' => 'crm-intended@example.test', 'password' => 'password123'])
            ->assertRedirect(route('crm.board'));
    }

    /**
     * D-02/U-02, required case (b): the actor was on /dashboard under team A
     * (role has analytics.view) and switches into team B, where the role
     * only has crm.view. The switch always lands on the new role's home —
     * here that happens to also be the one outcome that avoids a 403, but
     * the point after this fix is that it is ALWAYS the home, not that it
     * was specifically rescued from a forbidden page (see the next test).
     */
    public function test_switching_from_dashboard_into_a_crm_view_only_role_lands_on_crm(): void
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
        $response->assertSessionHas('status', __('agents.nav.teams.switched'));
        $this->get(route('crm.board'))->assertOk();
        $this->assertSame($teamBOwner->current_team_id, $actor->fresh()->current_team_id);
    }

    /**
     * D-02/U-02, required case (c): the page the actor switched FROM is
     * still open under the new role — proving the fix is "always home",
     * never "back() unless forbidden". The old implementation would have
     * returned here via back(), since agents.index stays open under role
     * B too; the new one goes to crm.board instead, because that is role
     * B's actual home (crm.view outranks agents.view in HomeDestination's
     * own candidate order) and home is now unconditional.
     */
    public function test_switching_into_a_role_that_can_still_open_the_previous_page_lands_on_home_not_back(): void
    {
        $teamAOwner = User::factory()->create();
        $teamBOwner = User::factory()->create();
        $actor = User::factory()->create();

        $roleA = $this->customRole($teamAOwner, [PermissionCatalog::AGENTS_VIEW]);
        $this->addMember($teamAOwner, $actor, $roleA);
        $actor->forceFill(['current_team_id' => $teamAOwner->current_team_id])->save();

        $roleB = $this->customRole($teamBOwner, [PermissionCatalog::AGENTS_VIEW, PermissionCatalog::CRM_VIEW]);
        $this->addMember($teamBOwner, $actor, $roleB);

        $this->actingAs($actor)->get(route('agents.index'))->assertOk();

        $response = $this->from(route('agents.index'))
            ->post(route('teams.switch'), ['team_id' => $teamBOwner->current_team_id]);

        // Role B can still open agents.index (it keeps agents.view) — a
        // back()-based implementation would have returned exactly there.
        $this->assertTrue($actor->fresh()->can(PermissionCatalog::AGENTS_VIEW));
        $response->assertRedirect(route('crm.board'));
    }

    /**
     * D-02/U-02, required case (a): the deepest, most concrete instance of
     * the bug this closes. A project board detail page is bound to a
     * specific row of the OLD team (ProjectAccess::authorize() 404s the
     * instant activeTeamId() !== board->team_id) — not one of
     * HomeDestination's 6 known candidates, so the old implementation's
     * "not recognized, assumed fine" default sent back() straight into a
     * 404. The new implementation never looks at the previous page at all.
     */
    public function test_switching_from_a_project_board_detail_of_the_old_team_lands_on_the_new_role_home(): void
    {
        $teamAOwner = User::factory()->create();
        $teamBOwner = User::factory()->create();
        $actor = User::factory()->create();

        $roleA = $this->customRole($teamAOwner, [PermissionCatalog::PROJECTS_VIEW]);
        $this->addMember($teamAOwner, $actor, $roleA);
        $actor->forceFill(['current_team_id' => $teamAOwner->current_team_id])->save();
        $board = app(ProjectBoards::class)->create($teamAOwner->current_team_id, $teamAOwner->id, 'Launch');

        $roleB = $this->customRole($teamBOwner, [PermissionCatalog::CRM_VIEW]);
        $this->addMember($teamBOwner, $actor, $roleB);

        $boardUrl = route('projects.boards.show', $board);
        $this->actingAs($actor)->get($boardUrl)->assertOk();

        $response = $this->from($boardUrl)
            ->post(route('teams.switch'), ['team_id' => $teamBOwner->current_team_id]);

        $response->assertRedirect(route('crm.board'));
        $response->assertSessionHas('status', __('agents.nav.teams.switched'));
    }

    /** D-02/U-02, required case (e): the switch's status flash survives the change to an unconditional redirect. */
    public function test_a_switch_always_carries_the_switched_status_flash(): void
    {
        $teamAOwner = User::factory()->create();
        $teamBOwner = User::factory()->create();
        $actor = User::factory()->create();

        $roleA = $this->customRole($teamAOwner, [PermissionCatalog::ANALYTICS_VIEW]);
        $this->addMember($teamAOwner, $actor, $roleA);
        $actor->forceFill(['current_team_id' => $teamAOwner->current_team_id])->save();

        $roleB = $this->customRole($teamBOwner, [PermissionCatalog::ANALYTICS_VIEW]);
        $this->addMember($teamBOwner, $actor, $roleB);

        $response = $this->actingAs($actor)
            ->post(route('teams.switch'), ['team_id' => $teamBOwner->current_team_id]);

        $response->assertSessionHas('status', __('agents.nav.teams.switched'));
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
