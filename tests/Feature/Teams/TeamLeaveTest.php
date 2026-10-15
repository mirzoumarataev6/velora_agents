<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Projects\ProjectBoards;
use App\Services\Teams\CreatesTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G-01: a self-service way to leave a team — the gap destroy()'s own
 * unconditional self-removal block (Stage D1.5, H6) deliberately left for
 * this stage to close, not a bug in that guard.
 */
class TeamLeaveTest extends TestCase
{
    use RefreshDatabase;

    /** Promotes $owner's own personal team into a real, named one — leave() must never see is_personal here. */
    private function explicitTeamOwner(): User
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales '.uniqid());

        return $owner;
    }

    private function role(int $teamId, string $slug): Role
    {
        return Role::where('team_id', $teamId)->where('slug', $slug)->sole();
    }

    private function join(User $user, int $teamId, Role $role): TeamMembership
    {
        return TeamMembership::create([
            'team_id' => $teamId, 'user_id' => $user->id,
            'role_id' => $role->id, 'joined_at' => now(),
        ]);
    }

    /** Required case (a). */
    public function test_an_operator_can_leave_and_lands_on_their_own_personal_team(): void
    {
        $owner = $this->explicitTeamOwner();
        $member = User::factory()->create();
        $personalTeamId = $member->current_team_id;
        $membership = $this->join($member, $owner->current_team_id, $this->role($owner->current_team_id, PermissionCatalog::OPERATOR));
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $response = $this->actingAs($member)->post(route('teams.leave'));

        $response->assertRedirect();
        $this->assertDatabaseMissing('team_user', ['id' => $membership->id]);
        $this->assertSame($personalTeamId, $member->fresh()->current_team_id);
    }

    /** Required case (b): the same path, for a role one rung up. */
    public function test_an_admin_can_leave_the_same_way(): void
    {
        $owner = $this->explicitTeamOwner();
        $member = User::factory()->create();
        $personalTeamId = $member->current_team_id;
        $membership = $this->join($member, $owner->current_team_id, $this->role($owner->current_team_id, PermissionCatalog::ADMIN));
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $response = $this->actingAs($member)->post(route('teams.leave'));

        $response->assertRedirect();
        $this->assertDatabaseMissing('team_user', ['id' => $membership->id]);
        $this->assertSame($personalTeamId, $member->fresh()->current_team_id);
    }

    /** Required case (c): the Owner cannot leave; the membership is never touched. */
    public function test_the_owner_is_forbidden_and_the_membership_remains(): void
    {
        $owner = $this->explicitTeamOwner();
        $membership = $owner->membershipIn($owner->current_team_id);

        $this->actingAs($owner)->post(route('teams.leave'))->assertForbidden();

        $this->assertDatabaseHas('team_user', ['id' => $membership->id]);
        $this->assertSame($owner->current_team_id, $owner->fresh()->current_team_id);
    }

    /**
     * Required case (d): the personal-team check. Under this app's
     * invariants a personal team's only member is always its Owner, so
     * is_owner already blocks this case too — this test's request is the
     * same shape as the one above in practice, but it exercises a user
     * whose active team genuinely IS still personal (never promoted via
     * CreatesTeam), proving the independent is_personal guard is there as
     * well rather than relying on is_owner alone to ever hold.
     */
    public function test_the_personal_team_cannot_be_left(): void
    {
        $user = User::factory()->create();
        $membership = $user->membershipIn($user->current_team_id);

        $this->actingAs($user)->post(route('teams.leave'))->assertForbidden();

        $this->assertDatabaseHas('team_user', ['id' => $membership->id]);
    }

    /**
     * Required case (e), first half: a user cannot leave a team they are
     * not a member of. A stale/forged current_team_id pointing at such a
     * team makes User::activeTeamId() itself return null (it only ever
     * trusts current_team_id while membershipIn() confirms it) — the same
     * 403 permittedActiveTeamId() already gives every other team.*
     * endpoint for exactly this case, not a 404 from inside the
     * transaction (that inner check is a defense against a genuine
     * concurrent removal between this request's activeTeamId() read and
     * its own lockForUpdate(), not something a normal request can reach).
     */
    public function test_a_stale_current_team_id_for_a_team_never_joined_is_forbidden(): void
    {
        $stranger = User::factory()->create();
        $member = User::factory()->create();
        $member->forceFill(['current_team_id' => $stranger->current_team_id])->save();

        $this->actingAs($member)->post(route('teams.leave'))->assertForbidden();

        $this->assertSame(1, TeamMembership::where('user_id', $member->id)->count());
        $this->assertSame($stranger->current_team_id, $member->fresh()->current_team_id);
    }

    /**
     * Required case (e), second half: leave() reads no request body at
     * all to decide whose membership to delete — these fields are exactly
     * what a forged request pointing at someone else would try.
     */
    public function test_extra_request_fields_cannot_redirect_the_leave_to_another_members_row(): void
    {
        $owner = $this->explicitTeamOwner();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $membership = $this->join($member, $owner->current_team_id, $this->role($owner->current_team_id, PermissionCatalog::OPERATOR));
        $otherMembership = $this->join($other, $owner->current_team_id, $this->role($owner->current_team_id, PermissionCatalog::VIEWER));
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($member)->post(route('teams.leave'), [
            'user_id' => $other->id,
            'membership_id' => $otherMembership->id,
            'team_id' => $otherMembership->team_id,
        ])->assertRedirect();

        $this->assertDatabaseMissing('team_user', ['id' => $membership->id]);
        $this->assertDatabaseHas('team_user', ['id' => $otherMembership->id]);
    }

    /**
     * Required case (f): the departed user loses access to the old team's
     * resources (a policy-guarded page 404s once activeTeamId() no longer
     * matches), and the remaining member's own row is untouched.
     */
    public function test_after_leaving_access_to_the_old_teams_resources_is_gone_and_other_members_are_unchanged(): void
    {
        $owner = $this->explicitTeamOwner();
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $this->join($member, $owner->current_team_id, $this->role($owner->current_team_id, PermissionCatalog::OPERATOR));
        $otherMembership = $this->join($otherMember, $owner->current_team_id, $this->role($owner->current_team_id, PermissionCatalog::OPERATOR));
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $board = app(ProjectBoards::class)->create($owner->current_team_id, $owner->id, 'Launch');
        $this->actingAs($member)->get(route('projects.boards.show', $board))->assertOk();

        $this->actingAs($member)->post(route('teams.leave'))->assertRedirect();

        $this->actingAs($member)->get(route('projects.boards.show', $board))->assertNotFound();
        $this->assertDatabaseHas('team_user', ['id' => $otherMembership->id]);
        $this->assertSame(
            PermissionCatalog::OPERATOR,
            $otherMember->fresh()->membershipIn($owner->current_team_id)->role->slug,
        );
    }

    /** Required case (g). */
    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->post(route('teams.leave'))->assertRedirect(route('login'));
    }
}
