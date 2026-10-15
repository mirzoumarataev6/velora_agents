<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMemberManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_change_a_members_role_within_the_active_team(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $membership = $this->join($member, $owner, PermissionCatalog::OPERATOR);
        $viewer = $this->role($owner, PermissionCatalog::VIEWER);

        $this->actingAs($owner)->patch(route('teams.members.update', $membership), [
            'role_id' => $viewer->id,
        ])->assertRedirect();

        $this->assertSame($viewer->id, $membership->fresh()->role_id);
        $this->assertSame(PermissionCatalog::VIEWER, $member->fresh()->membershipIn($owner->current_team_id)->role->slug);
    }

    public function test_operator_cannot_change_or_remove_a_member(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->join($actor, $owner, PermissionCatalog::OPERATOR);
        $membership = $this->join($target, $owner, PermissionCatalog::OPERATOR);
        $actor->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($actor)->patch(route('teams.members.update', $membership), [
            'role_id' => $this->role($owner, PermissionCatalog::VIEWER)->id,
        ])->assertForbidden();
        $this->delete(route('teams.members.destroy', $membership))->assertForbidden();

        $this->assertDatabaseHas('team_user', ['id' => $membership->id, 'role_id' => $membership->role_id]);
    }

    public function test_owner_cannot_change_or_remove_an_owner_membership(): void
    {
        $owner = User::factory()->create();
        $ownMembership = $owner->membershipIn($owner->current_team_id);
        $otherOwner = User::factory()->create();
        $otherOwnerMembership = $this->join($otherOwner, $owner, PermissionCatalog::OWNER);

        foreach ([$ownMembership, $otherOwnerMembership] as $membership) {
            $this->actingAs($owner)->patch(route('teams.members.update', $membership), [
                'role_id' => $this->role($owner, PermissionCatalog::VIEWER)->id,
            ])->assertForbidden();
            $this->delete(route('teams.members.destroy', $membership))->assertForbidden();
            $this->assertDatabaseHas('team_user', ['id' => $membership->id, 'role_id' => $membership->role_id]);
        }
    }

    public function test_member_and_role_must_belong_to_the_active_team(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $otherMember = User::factory()->create();
        $otherMembership = $this->join($otherMember, $otherOwner, PermissionCatalog::OPERATOR);
        $ourMember = User::factory()->create();
        $ourMembership = $this->join($ourMember, $owner, PermissionCatalog::OPERATOR);

        $this->actingAs($owner)->patch(route('teams.members.update', $otherMembership), [
            'role_id' => $this->role($owner, PermissionCatalog::VIEWER)->id,
        ])->assertNotFound();
        $this->delete(route('teams.members.destroy', $otherMembership))->assertNotFound();

        $this->patch(route('teams.members.update', $ourMembership), [
            'role_id' => $this->role($otherOwner, PermissionCatalog::VIEWER)->id,
        ])->assertSessionHasErrors('role_id', null, 'team');

        $this->assertDatabaseHas('team_user', ['id' => $otherMembership->id]);
        $this->assertSame($this->role($owner, PermissionCatalog::OPERATOR)->id, $ourMembership->fresh()->role_id);
    }

    public function test_stale_current_team_cannot_authorize_member_changes(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $membership = $this->join($member, $owner, PermissionCatalog::OPERATOR);
        $owner->forceFill(['current_team_id' => null])->save();

        $this->actingAs($owner)->delete(route('teams.members.destroy', $membership))->assertForbidden();

        $this->assertDatabaseHas('team_user', ['id' => $membership->id]);
    }

    public function test_removal_repoints_the_members_active_team_to_an_existing_membership(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $personalTeamId = $member->current_team_id;
        $membership = $this->join($member, $owner, PermissionCatalog::OPERATOR);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($owner)->delete(route('teams.members.destroy', $membership))->assertRedirect();

        $this->assertDatabaseMissing('team_user', ['id' => $membership->id]);
        $this->assertSame($personalTeamId, $member->fresh()->activeTeamId());
    }

    public function test_removal_creates_a_personal_team_when_no_membership_remains(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->createQuietly();
        $membership = $this->join($member, $owner, PermissionCatalog::OPERATOR);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($owner)->delete(route('teams.members.destroy', $membership))->assertRedirect();

        $member->refresh();
        $this->assertNotSame($owner->current_team_id, $member->activeTeamId());
        $this->assertTrue($member->isOwnerOf($member->activeTeamId()));
        $this->assertDatabaseMissing('team_user', ['id' => $membership->id]);
    }

    public function test_removal_keeps_another_current_team_unchanged(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $personalTeamId = $member->current_team_id;
        $membership = $this->join($member, $owner, PermissionCatalog::OPERATOR);

        $this->actingAs($owner)->delete(route('teams.members.destroy', $membership))->assertRedirect();

        $this->assertSame($personalTeamId, $member->fresh()->activeTeamId());
    }

    /**
     * A custom role carrying ONLY `team.members.remove` must not be a
     * backdoor around the escalation check `update()` already enforces for
     * role changes: removing a member who outranks you is exactly as much
     * of an escalation as promoting yourself into their role would be.
     */
    public function test_a_members_remove_only_custom_role_cannot_remove_an_admin_or_a_viewer(): void
    {
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $remover = Role::create(['team_id' => $owner->current_team_id, 'slug' => 'remover', 'name' => 'Remover']);
        $remover->syncPermissions([PermissionCatalog::TEAM_MEMBERS_VIEW, PermissionCatalog::TEAM_MEMBERS_REMOVE]);
        TeamMembership::create([
            'team_id' => $owner->current_team_id, 'user_id' => $actor->id,
            'role_id' => $remover->id, 'joined_at' => now(),
        ]);
        $actor->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $admin = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);
        $viewer = $this->join(User::factory()->create(), $owner, PermissionCatalog::VIEWER);

        $this->actingAs($actor)->delete(route('teams.members.destroy', $admin))->assertForbidden();
        $this->delete(route('teams.members.destroy', $viewer))->assertForbidden();

        $this->assertDatabaseHas('team_user', ['id' => $admin->id]);
        $this->assertDatabaseHas('team_user', ['id' => $viewer->id]);
    }

    public function test_admin_can_remove_an_operator(): void
    {
        $owner = User::factory()->create();
        $admin = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);
        $admin->user->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $operator = $this->join(User::factory()->create(), $owner, PermissionCatalog::OPERATOR);

        $this->actingAs($admin->user)->delete(route('teams.members.destroy', $operator))->assertRedirect();

        $this->assertDatabaseMissing('team_user', ['id' => $operator->id]);
    }

    public function test_admin_can_remove_a_viewer(): void
    {
        $owner = User::factory()->create();
        $admin = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);
        $admin->user->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $viewer = $this->join(User::factory()->create(), $owner, PermissionCatalog::VIEWER);

        $this->actingAs($admin->user)->delete(route('teams.members.destroy', $viewer))->assertRedirect();

        $this->assertDatabaseMissing('team_user', ['id' => $viewer->id]);
    }

    /**
     * H6: canGrant() alone passed here before this fix, because an Admin
     * removing another Admin trivially already holds every permission the
     * target role holds (it is the same bundle) — the exact shape of
     * escalation `update()`'s own canGrant() checks are meant to prevent.
     */
    public function test_admin_cannot_remove_another_admin(): void
    {
        $owner = User::factory()->create();
        $actor = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);
        $actor->user->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $targetAdmin = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);

        $this->actingAs($actor->user)->delete(route('teams.members.destroy', $targetAdmin))->assertForbidden();

        $this->assertDatabaseHas('team_user', ['id' => $targetAdmin->id]);
    }

    /**
     * H6: destroy() is a removal endpoint, not a self-service leave-team
     * one (that is separate future work, G-01/Stage D2) — in this stage
     * self-removal through it is forbidden for every role, Owner included
     * (whose self-removal is already blocked by the is_owner check).
     */
    public function test_admin_cannot_remove_self(): void
    {
        $owner = User::factory()->create();
        $actor = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);
        $actor->user->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($actor->user)->delete(route('teams.members.destroy', $actor))->assertForbidden();

        $this->assertDatabaseHas('team_user', ['id' => $actor->id]);
    }

    /** The Owner is exempt from the new peer-manager restriction — only the Owner may remove another manager. */
    public function test_owner_can_remove_an_admin(): void
    {
        $owner = User::factory()->create();
        $targetAdmin = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);

        $this->actingAs($owner)->delete(route('teams.members.destroy', $targetAdmin))->assertRedirect();

        $this->assertDatabaseMissing('team_user', ['id' => $targetAdmin->id]);
    }

    /**
     * The same peer-manager hole, on the role-CHANGE path: canGrant() on
     * the admin's own CURRENT role passes trivially against another Admin
     * actor for the same reason it does in destroy() — demoting a peer
     * manager without the Owner's involvement is the same escalation shape
     * as removing them.
     */
    public function test_admin_cannot_change_another_admins_role(): void
    {
        $owner = User::factory()->create();
        $actor = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);
        $actor->user->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $targetAdmin = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);
        $viewerRole = $this->role($owner, PermissionCatalog::VIEWER);

        $this->actingAs($actor->user)->patch(route('teams.members.update', $targetAdmin), [
            'role_id' => $viewerRole->id,
        ])->assertForbidden();

        $this->assertSame($this->role($owner, PermissionCatalog::ADMIN)->id, $targetAdmin->fresh()->role_id);
    }

    /**
     * Self-targeting stays exempt from the peer-manager check above: an
     * Admin stepping themselves down is not the escalation the check
     * exists to stop (canGrant() on the NEW role already stops a self
     * UPGRADE, since it can never hold a permission the actor lacks).
     */
    public function test_admin_can_still_change_their_own_role(): void
    {
        $owner = User::factory()->create();
        $actor = $this->join(User::factory()->create(), $owner, PermissionCatalog::ADMIN);
        $actor->user->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $viewerRole = $this->role($owner, PermissionCatalog::VIEWER);

        $this->actingAs($actor->user)->patch(route('teams.members.update', $actor), [
            'role_id' => $viewerRole->id,
        ])->assertRedirect();

        $this->assertSame($viewerRole->id, $actor->fresh()->role_id);
    }

    private function join(User $member, User $owner, string $roleSlug): TeamMembership
    {
        return TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => $this->role($owner, $roleSlug)->id,
            'joined_at' => now(),
        ]);
    }

    private function role(User $owner, string $slug): Role
    {
        return Role::where('team_id', $owner->current_team_id)->where('slug', $slug)->sole();
    }
}
