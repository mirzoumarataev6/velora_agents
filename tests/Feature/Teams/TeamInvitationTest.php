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

/**
 * Stage 3b: inviting someone by email to join a team, and accepting that
 * invitation. No Policy class — see TeamInvitationController's own
 * docblock for why its `isOwnerOf()` checks are inline and temporary.
 */
class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');

        return $owner;
    }

    private function roleOn(User $owner, string $slug): Role
    {
        return Role::where('team_id', $owner->current_team_id)->where('slug', $slug)->firstOrFail();
    }

    // --- store() -------------------------------------------------------

    public function test_the_owner_can_invite_someone_by_email(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);

        $this->actingAs($owner)->post(route('teams.invite'), [
            'email' => 'teammate@example.com',
            'role_id' => $role->id,
        ])->assertRedirect();

        $invitation = Invitation::sole();
        $this->assertSame($owner->current_team_id, $invitation->team_id);
        $this->assertSame('teammate@example.com', $invitation->email);
        $this->assertSame($role->id, $invitation->role_id);
        $this->assertSame($owner->id, $invitation->invited_by);
        $this->assertSame(Invitation::STATUS_PENDING, $invitation->status);

        Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->invitation->is($invitation));
    }

    public function test_a_non_owner_cannot_invite_anyone(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);

        $teammate = User::factory()->createQuietly();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $teammate->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
        $teammate->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($teammate)->post(route('teams.invite'), [
            'email' => 'someone@example.com',
            'role_id' => $role->id,
        ])->assertForbidden();

        $this->assertSame(0, Invitation::count());
        Mail::assertNothingQueued();
    }

    public function test_inviting_an_email_with_an_already_pending_invite_is_rejected(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);

        $this->actingAs($owner)->post(route('teams.invite'), [
            'email' => 'teammate@example.com',
            'role_id' => $role->id,
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('teams.invite'), [
            'email' => 'teammate@example.com',
            'role_id' => $role->id,
        ])->assertSessionHasErrors('email', null, 'team');

        $this->assertSame(1, Invitation::count(), 'A second row was created instead of being rejected.');
    }

    /** A role from another team must never be assignable, even with a valid id. */
    public function test_a_role_from_another_team_is_rejected(): void
    {
        $owner = $this->owner();
        $otherOwner = $this->owner();
        $foreignRole = $this->roleOn($otherOwner, PermissionCatalog::OPERATOR);

        $this->actingAs($owner)->post(route('teams.invite'), [
            'email' => 'teammate@example.com',
            'role_id' => $foreignRole->id,
        ])->assertSessionHasErrors('role_id', null, 'team');

        $this->assertSame(0, Invitation::count());
    }

    // --- destroy() -------------------------------------------------------

    public function test_the_owner_can_revoke_a_pending_invitation(): void
    {
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
        ]);

        $this->actingAs($owner)->delete(route('teams.invitations.destroy', $invitation))
            ->assertRedirect();

        $this->assertSame(Invitation::STATUS_REVOKED, $invitation->fresh()->status);
    }

    public function test_a_non_owner_cannot_revoke_an_invitation(): void
    {
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->delete(route('teams.invitations.destroy', $invitation))
            ->assertForbidden();

        $this->assertSame(Invitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    /**
     * A member holding only `team.members.invite` must not be able to
     * cancel an invitation for a role carrying permissions they themselves
     * lack — the same escalation-shaped gap TeamMemberController::destroy()
     * closes for removing a live member, applied to a pending one instead.
     */
    public function test_an_invite_only_role_cannot_revoke_an_invitation_for_a_more_powerful_role(): void
    {
        $owner = $this->owner();
        $inviterRole = Role::create(['team_id' => $owner->current_team_id, 'slug' => 'inviter', 'name' => 'Inviter']);
        $inviterRole->syncPermissions([PermissionCatalog::TEAM_MEMBERS_INVITE]);
        $actor = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id, 'user_id' => $actor->id,
            'role_id' => $inviterRole->id, 'joined_at' => now(),
        ]);
        $actor->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $admin = $this->roleOn($owner, PermissionCatalog::ADMIN);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $admin->id,
            'invited_by' => $owner->id,
        ]);

        $this->actingAs($actor)->delete(route('teams.invitations.destroy', $invitation))
            ->assertForbidden();

        $this->assertSame(Invitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    // --- accept() --------------------------------------------------------

    public function test_accepting_creates_a_membership_with_the_invited_role_on_the_right_team(): void
    {
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'teammate@example.com',
        ]);

        $teammate = User::factory()->createQuietly(['email' => 'teammate@example.com']);

        $response = $this->actingAs($teammate)->get(route('invitations.accept', $invitation->token));

        $response->assertRedirect(route('dashboard'));

        $membership = TeamMembership::where('team_id', $owner->current_team_id)->where('user_id', $teammate->id)->sole();
        $this->assertSame($role->id, $membership->role_id);
        $this->assertSame($owner->current_team_id, $teammate->fresh()->current_team_id, 'That team should now be active.');
        $this->assertSame(Invitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_an_unauthenticated_visitor_is_sent_to_log_in_and_returned_after(): void
    {
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'teammate@example.com',
        ]);

        $this->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('login'));

        $this->assertSame(route('invitations.accept', $invitation->token), session('url.intended'));
    }

    public function test_accepting_with_a_mismatched_logged_in_email_is_rejected(): void
    {
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'teammate@example.com',
        ]);

        $wrongUser = User::factory()->create(['email' => 'someoneelse@example.com']);

        $response = $this->actingAs($wrongUser)->get(route('invitations.accept', $invitation->token));

        $response->assertForbidden();
        $this->assertSame(0, TeamMembership::where('team_id', $owner->current_team_id)->where('user_id', $wrongUser->id)->count());
        $this->assertSame(Invitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    public function test_an_expired_invitation_is_rejected_cleanly(): void
    {
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'teammate@example.com',
            'expires_at' => now()->subDay(),
        ]);
        $teammate = User::factory()->create(['email' => 'teammate@example.com']);

        $response = $this->actingAs($teammate)->get(route('invitations.accept', $invitation->token));

        $response->assertStatus(410);
        $this->assertSame(0, TeamMembership::where('team_id', $owner->current_team_id)->where('user_id', $teammate->id)->count());
    }

    public function test_an_already_accepted_invitation_is_rejected_cleanly(): void
    {
        $owner = $this->owner();
        $role = $this->roleOn($owner, PermissionCatalog::OPERATOR);
        $invitation = Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'teammate@example.com',
            'status' => Invitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);
        $teammate = User::factory()->create(['email' => 'teammate@example.com']);

        $response = $this->actingAs($teammate)->get(route('invitations.accept', $invitation->token));

        $response->assertStatus(409);
    }

    public function test_a_bad_token_is_rejected_cleanly_not_a_500(): void
    {
        $this->get(route('invitations.accept', 'not-a-real-token'))
            ->assertStatus(404);
    }
}
