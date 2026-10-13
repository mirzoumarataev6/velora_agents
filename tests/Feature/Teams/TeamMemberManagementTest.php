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
