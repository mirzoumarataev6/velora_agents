<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests this action in isolation, one call producing one team to assert
 * against. Users are built with `createQuietly()` rather than `create()` so
 * UserObserver's own call to this same action (Stage 2a's auto personal
 * team on signup) never runs first and leaves a second, untested team
 * lying around for these assertions to trip over.
 */
class CreatesPersonalTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_team_named_after_the_user(): void
    {
        $user = User::factory()->createQuietly(['name' => 'Alice Example']);

        $team = app(CreatesPersonalTeam::class)->create($user);

        $this->assertSame("Alice Example's Team", $team->name);
        $this->assertSame($user->id, $team->created_by);
        $this->assertNotEmpty($team->slug);
    }

    public function test_it_falls_back_to_a_generic_name_when_the_user_has_no_name(): void
    {
        $user = User::factory()->createQuietly(['name' => '']);

        $team = app(CreatesPersonalTeam::class)->create($user);

        $this->assertSame('My Team', $team->name);
    }

    public function test_it_copies_all_four_role_templates_onto_the_new_team(): void
    {
        $user = User::factory()->createQuietly();

        $team = app(CreatesPersonalTeam::class)->create($user);

        $roles = Role::where('team_id', $team->id)->get()->keyBy('slug');

        foreach (PermissionCatalog::systemRoleSlugs() as $slug) {
            $this->assertTrue($roles->has($slug), "missing {$slug} role on the new team");
            $this->assertSame(
                PermissionCatalog::defaultMatrix()[$slug],
                $roles->get($slug)->permissionKeys(),
                "{$slug} role's permission bundle does not match the catalog default"
            );
        }
    }

    public function test_it_makes_the_user_the_owner_of_the_new_team(): void
    {
        $user = User::factory()->createQuietly();

        $team = app(CreatesPersonalTeam::class)->create($user);

        $membership = TeamMembership::where('team_id', $team->id)->where('user_id', $user->id)->first();

        $this->assertNotNull($membership);
        $this->assertTrue($membership->role->is_owner);
        $this->assertSame(PermissionCatalog::OWNER, $membership->role->slug);
    }

    public function test_two_users_get_two_independent_teams_with_independent_roles(): void
    {
        $alice = User::factory()->createQuietly(['name' => 'Alice']);
        $bob = User::factory()->createQuietly(['name' => 'Bob']);

        $teamA = app(CreatesPersonalTeam::class)->create($alice);
        $teamB = app(CreatesPersonalTeam::class)->create($bob);

        $this->assertNotSame($teamA->id, $teamB->id);

        $ownerRoleA = Role::where('team_id', $teamA->id)->where('slug', PermissionCatalog::OWNER)->first();
        $ownerRoleB = Role::where('team_id', $teamB->id)->where('slug', PermissionCatalog::OWNER)->first();

        $this->assertNotSame($ownerRoleA->id, $ownerRoleB->id);

        // Editing one team's role bundle must never touch the other's.
        $ownerRoleA->syncPermissions([PermissionCatalog::AGENTS_VIEW]);

        $this->assertSame([PermissionCatalog::AGENTS_VIEW], $ownerRoleA->permissionKeys());
        $this->assertSame(PermissionCatalog::all(), $ownerRoleB->permissionKeys());
    }
}
