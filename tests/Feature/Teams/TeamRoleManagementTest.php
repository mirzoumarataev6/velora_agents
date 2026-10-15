<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Teams\CreatesTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create();
        app(CreatesTeam::class)->create($owner, 'Sales');

        return $owner;
    }

    public function test_owner_creates_a_custom_role_with_only_valid_catalog_permissions(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('teams.roles.store'), [
            'name' => 'Sales coach',
            'permissions' => [PermissionCatalog::AGENTS_VIEW, PermissionCatalog::CONVERSATIONS_VIEW, PermissionCatalog::CONVERSATIONS_REPLY],
            'team_id' => User::factory()->create()->current_team_id,
            'is_owner' => true,
        ])->assertRedirect();

        $role = Role::where('team_id', $owner->current_team_id)->where('name', 'Sales coach')->sole();
        $this->assertFalse($role->is_owner);
        $this->assertSame([PermissionCatalog::AGENTS_VIEW, PermissionCatalog::CONVERSATIONS_VIEW, PermissionCatalog::CONVERSATIONS_REPLY], $role->permissionKeys());
        $this->assertStringStartsWith('sales-coach-', $role->slug);
    }

    public function test_unknown_duplicate_and_malformed_permission_input_is_rejected(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        foreach ([
            [['evil.permission'], 'permissions.0'],
            [[PermissionCatalog::AGENTS_VIEW, PermissionCatalog::AGENTS_VIEW], 'permissions.0'],
            ['agents.view', 'permissions'],
        ] as [$permissions, $errorKey]) {
            $this->post(route('teams.roles.store'), [
                'name' => 'Invalid role',
                'permissions' => $permissions,
            ])->assertSessionHasErrors($errorKey, null, 'roles');
        }

        $this->assertDatabaseMissing('roles', ['team_id' => $owner->current_team_id, 'name' => 'Invalid role']);
    }

    public function test_manage_permissions_are_rejected_without_their_view_dependency(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        foreach ([
            [PermissionCatalog::CRM_MANAGE],
            [PermissionCatalog::ANALYTICS_MANAGE],
            [PermissionCatalog::CRM_MANAGE, PermissionCatalog::ANALYTICS_MANAGE],
        ] as $permissions) {
            $this->post(route('teams.roles.store'), [
                'name' => 'Invalid dependency role',
                'permissions' => $permissions,
            ])->assertSessionHasErrors('permissions', null, 'roles');
        }

        $this->assertDatabaseMissing('roles', ['team_id' => $owner->current_team_id, 'name' => 'Invalid dependency role']);

        $this->post(route('teams.roles.store'), [
            'name' => 'Valid CRM role',
            'permissions' => [PermissionCatalog::CRM_VIEW, PermissionCatalog::CRM_MANAGE],
        ])->assertRedirect();

        $role = Role::where('team_id', $owner->current_team_id)->where('name', 'Valid CRM role')->sole();
        $this->assertSame([PermissionCatalog::CRM_VIEW, PermissionCatalog::CRM_MANAGE], $role->permissionKeys());
    }

    /**
     * Stage C2 generalized requires() beyond crm/analytics. A specific,
     * hardcoded pair rather than one already covered by the generic test
     * below — agents.create had NO dependency before this stage, so this
     * fails on a commit before it landed, where the generic loop test
     * would not (it only ever checks whatever requires() already has).
     */
    public function test_agents_create_is_rejected_without_agents_view(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('teams.roles.store'), [
            'name' => 'No view role',
            'permissions' => [PermissionCatalog::AGENTS_CREATE],
        ])->assertSessionHasErrors('permissions', null, 'roles');

        $this->assertDatabaseMissing('roles', ['team_id' => $owner->current_team_id, 'name' => 'No view role']);

        $this->post(route('teams.roles.store'), [
            'name' => 'With view role',
            'permissions' => [PermissionCatalog::AGENTS_VIEW, PermissionCatalog::AGENTS_CREATE],
        ])->assertRedirect();

        $role = Role::where('team_id', $owner->current_team_id)->where('name', 'With view role')->sole();
        $this->assertSame([PermissionCatalog::AGENTS_VIEW, PermissionCatalog::AGENTS_CREATE], $role->permissionKeys());
    }

    /**
     * The validation rule itself reads PermissionCatalog::requires()
     * generically — this proves that genericity by walking the WHOLE map,
     * not just the crm/analytics pair, so a future addition to requires()
     * is covered by this test without anyone having to remember to extend
     * it by hand.
     */
    public function test_every_entry_in_the_dependency_map_is_rejected_server_side_when_submitted_alone(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        foreach (PermissionCatalog::requires() as $permission => $dependencies) {
            $this->post(route('teams.roles.store'), [
                'name' => "Role for {$permission}",
                'permissions' => [$permission],
            ])->assertSessionHasErrors('permissions', null, 'roles');

            $this->assertDatabaseMissing('roles', ['team_id' => $owner->current_team_id, 'name' => "Role for {$permission}"]);

            $this->post(route('teams.roles.store'), [
                'name' => "Role for {$permission}",
                'permissions' => array_merge($dependencies, [$permission]),
            ])->assertRedirect();

            $role = Role::where('team_id', $owner->current_team_id)->where('name', "Role for {$permission}")->sole();
            $this->assertEqualsCanonicalizing(array_merge($dependencies, [$permission]), $role->permissionKeys());
        }
    }

    public function test_same_name_is_allowed_in_another_team_but_not_twice_in_one_team(): void
    {
        $owner = $this->owner();
        $otherOwner = $this->owner();

        $this->actingAs($owner)->post(route('teams.roles.store'), ['name' => 'Coach'])->assertRedirect();
        $this->post(route('teams.roles.store'), ['name' => 'Coach'])
            ->assertSessionHasErrors('name', null, 'roles');
        $this->actingAs($otherOwner)->post(route('teams.roles.store'), ['name' => 'Coach'])->assertRedirect();

        $this->assertSame(2, Role::where('name', 'Coach')->count());
    }

    public function test_owner_can_rename_a_custom_role_and_replace_its_permission_bundle(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('teams.roles.store'), [
            'name' => 'Coach',
            'permissions' => [PermissionCatalog::AGENTS_VIEW, PermissionCatalog::AGENTS_CREATE],
        ])->assertRedirect();
        $role = Role::where('team_id', $owner->current_team_id)->where('name', 'Coach')->sole();
        $originalSlug = $role->slug;

        $this->patch(route('teams.roles.update', $role), [
            'name' => 'Senior coach',
            'permissions' => [PermissionCatalog::CONVERSATIONS_VIEW, PermissionCatalog::CONVERSATIONS_REPLY],
        ])->assertRedirect();

        $this->assertSame('Senior coach', $role->fresh()->name);
        $this->assertSame($originalSlug, $role->fresh()->slug);
        $this->assertSame([PermissionCatalog::CONVERSATIONS_VIEW, PermissionCatalog::CONVERSATIONS_REPLY], $role->permissionKeys());

        $this->patch(route('teams.roles.update', $role), ['name' => 'Senior coach'])->assertRedirect();
        $this->assertSame([], $role->permissionKeys());
    }

    public function test_non_owner_system_permissions_can_change_but_system_name_cannot(): void
    {
        $owner = $this->owner();
        $admin = $this->role($owner, PermissionCatalog::ADMIN);

        $this->actingAs($owner)->patch(route('teams.roles.update', $admin), [
            'permissions' => [PermissionCatalog::AGENTS_VIEW],
        ])->assertRedirect();
        $this->assertSame([PermissionCatalog::AGENTS_VIEW], $admin->permissionKeys());

        $this->patch(route('teams.roles.update', $admin), [
            'name' => 'Not admin',
            'permissions' => [],
        ])->assertSessionHasErrors('name', null, 'roles');
        $this->assertSame('Admin', $admin->fresh()->name);
        $this->assertSame([PermissionCatalog::AGENTS_VIEW], $admin->permissionKeys());
    }

    public function test_owner_role_cannot_be_renamed_or_have_its_permissions_reduced(): void
    {
        $owner = $this->owner();
        $role = $this->role($owner, PermissionCatalog::OWNER);

        $this->actingAs($owner)->patch(route('teams.roles.update', $role), [
            'name' => 'Former owner',
            'permissions' => [],
        ])->assertForbidden();

        $this->assertSame('Owner', $role->fresh()->name);
        $this->assertSame(PermissionCatalog::all(), $role->permissionKeys());
    }

    public function test_operator_cannot_view_create_or_edit_roles(): void
    {
        $owner = $this->owner();
        $member = User::factory()->create();
        TeamMembership::create([
            'team_id' => $owner->current_team_id,
            'user_id' => $member->id,
            'role_id' => $this->role($owner, PermissionCatalog::OPERATOR)->id,
            'joined_at' => now(),
        ]);
        $member->forceFill(['current_team_id' => $owner->current_team_id])->save();
        $admin = $this->role($owner, PermissionCatalog::OPERATOR);

        $this->actingAs($member)->get(route('teams.roles.index'))->assertForbidden();
        $this->post(route('teams.roles.store'), ['name' => 'Unauthorized'])->assertForbidden();
        $this->patch(route('teams.roles.update', $admin), ['permissions' => []])->assertForbidden();
    }

    public function test_foreign_and_global_template_roles_cannot_be_edited_through_this_team(): void
    {
        $owner = $this->owner();
        $otherOwner = $this->owner();
        $foreignRole = $this->role($otherOwner, PermissionCatalog::ADMIN);
        $template = Role::whereNull('team_id')->where('slug', PermissionCatalog::ADMIN)->sole();

        $this->actingAs($owner)->patch(route('teams.roles.update', $foreignRole), [
            'permissions' => [],
        ])->assertNotFound();
        $this->get(route('teams.roles.index', ['edit' => $foreignRole->id]))->assertNotFound();
        $this->patch(route('teams.roles.update', $template), ['permissions' => []])->assertNotFound();

        $this->assertSame(PermissionCatalog::defaultMatrix()[PermissionCatalog::ADMIN], $foreignRole->permissionKeys());
    }

    public function test_stale_active_team_cannot_manage_roles(): void
    {
        $owner = $this->owner();
        $admin = $this->role($owner, PermissionCatalog::ADMIN);
        $owner->forceFill(['current_team_id' => null])->save();

        $this->actingAs($owner)->get(route('teams.roles.index'))->assertForbidden();
        $this->post(route('teams.roles.store'), ['name' => 'No team'])->assertForbidden();
        $this->patch(route('teams.roles.update', $admin), ['permissions' => []])->assertForbidden();
    }

    private function role(User $owner, string $slug): Role
    {
        return Role::where('team_id', $owner->current_team_id)->where('slug', $slug)->sole();
    }
}
