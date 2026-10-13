<?php

namespace Tests\Feature\Settings;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 3b-3: the settings page's "Team" tab — the invite form, the role
 * dropdown, and the pending-invitations list are all owner-only, gated the
 * same way SettingsController::index() gates every other owner-only query
 * (never run for anyone else).
 */
class TeamSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_sees_the_invite_form_and_role_choices(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get('/settings');

        $response->assertOk();
        $response->assertSee('data-i18n="settings.team.invite_heading"', false);
        foreach (PermissionCatalog::systemRoleSlugs() as $slug) {
            $response->assertSee(__('agents.nav.teams.roles.'.$slug));
        }
    }

    public function test_the_owner_sees_pending_invitations_they_sent(): void
    {
        $owner = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::OPERATOR)->firstOrFail();
        Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'pending@example.com',
        ]);

        $response = $this->actingAs($owner)->get('/settings');

        $response->assertOk();
        $response->assertSee('pending@example.com');
    }

    public function test_a_non_owner_sees_neither_the_invite_form_nor_pending_invitations(): void
    {
        $owner = User::factory()->create();
        $role = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::OPERATOR)->firstOrFail();
        Invitation::factory()->create([
            'team_id' => $owner->current_team_id,
            'role_id' => $role->id,
            'invited_by' => $owner->id,
            'email' => 'pending@example.com',
        ]);

        $teammate = User::factory()->createQuietly();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $teammate->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
        $teammate->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $response = $this->actingAs($teammate)->get('/settings');

        $response->assertOk();
        $response->assertDontSee('data-i18n="settings.team.invite_heading"', false);
        $response->assertDontSee('pending@example.com');
        $response->assertSee(__('settings.team.not_owner'));
    }

    public function test_owner_sees_only_active_team_members_with_controls_for_non_owners(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['name' => 'Visible teammate']);
        $otherOwner = User::factory()->create();
        $otherMember = User::factory()->create(['name' => 'Other team only']);
        $operator = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::OPERATOR)->sole();
        $otherOperator = Role::where('team_id', $otherOwner->current_team_id)->where('slug', PermissionCatalog::OPERATOR)->sole();
        $membership = TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => $operator->id,
            'joined_at' => now(),
        ]);
        TeamMembership::create([
            'team_id' => $otherOwner->current_team_id,
            'user_id' => $otherMember->id,
            'role_id' => $otherOperator->id,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get('/settings');

        $response->assertOk();
        $response->assertSee('Visible teammate');
        $response->assertDontSee('Other team only');
        $response->assertSee(route('teams.members.update', $membership));
        $response->assertSee(route('teams.members.destroy', $membership));
        $response->assertDontSee(route('teams.members.destroy', $owner->membershipIn($owner->current_team_id)));
    }

    public function test_non_owner_sees_no_member_list_or_member_controls(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['name' => 'Hidden teammate']);
        $operator = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::OPERATOR)->sole();
        $membership = TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => $operator->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $response = $this->actingAs($member)->get('/settings');

        $response->assertOk();
        $response->assertDontSee('data-i18n="settings.team.members_heading"', false);
        $response->assertDontSee(route('teams.members.destroy', $membership));
    }
}
