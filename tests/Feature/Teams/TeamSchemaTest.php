<?php

namespace Tests\Feature\Teams;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_four_global_role_templates_are_seeded(): void
    {
        $templates = Role::whereNull('team_id')->get()->keyBy('slug');

        foreach (PermissionCatalog::systemRoleSlugs() as $slug) {
            $this->assertTrue($templates->has($slug), "missing template role: {$slug}");
        }

        $owner = $templates->get(PermissionCatalog::OWNER);
        $this->assertTrue($owner->is_owner);
        $this->assertSame(PermissionCatalog::all(), $owner->permissionKeys());

        $this->assertFalse($templates->get(PermissionCatalog::ADMIN)->is_owner);
    }

    public function test_team_slug_is_unique(): void
    {
        Team::factory()->create(['name' => 'Acme']);

        $this->expectException(QueryException::class);
        Team::factory()->create(['slug' => Team::first()->slug]);
    }

    public function test_role_slug_is_unique_within_a_team(): void
    {
        $team = Team::factory()->create();
        Role::factory()->for($team)->create(['slug' => 'custom']);

        $this->expectException(QueryException::class);
        Role::factory()->for($team)->create(['slug' => 'custom']);
    }

    public function test_the_same_slug_may_exist_in_two_different_teams(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        Role::factory()->for($teamA)->create(['slug' => 'custom']);
        $roleB = Role::factory()->for($teamB)->create(['slug' => 'custom']);

        $this->assertNotNull($roleB->id);
    }

    public function test_a_user_cannot_have_two_membership_rows_on_the_same_team(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $role = Role::factory()->for($team)->create();

        TeamMembership::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        TeamMembership::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);
    }

    public function test_a_role_cannot_be_deleted_while_a_member_still_holds_it(): void
    {
        $team = Team::factory()->create();
        $role = Role::factory()->for($team)->create();

        TeamMembership::factory()->create(['team_id' => $team->id, 'role_id' => $role->id]);

        $this->expectException(QueryException::class);
        $role->delete();
    }

    public function test_role_permission_rejects_a_duplicate_key_for_the_same_role(): void
    {
        $role = Role::factory()->create();
        $role->syncPermissions([PermissionCatalog::AGENTS_VIEW]);

        $this->expectException(QueryException::class);
        \Illuminate\Support\Facades\DB::table('role_permission')->insert([
            'role_id' => $role->id,
            'permission' => PermissionCatalog::AGENTS_VIEW,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_invitation_token_is_unique(): void
    {
        $first = Invitation::factory()->create();

        $this->expectException(QueryException::class);
        Invitation::factory()->create(['token' => $first->token]);
    }

    public function test_invitation_token_is_generated_when_not_provided(): void
    {
        $invitation = Invitation::factory()->create();

        $this->assertNotEmpty($invitation->token);
    }
}
