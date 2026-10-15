<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `2026_10_26_000001_repair_roles_missing_permission_dependencies` fixes
 * role_permission rows that violate PermissionCatalog::requires() — data
 * that could only exist because, until Stage D1.2, nothing below the HTTP
 * layer enforced that map. Every fixture here grants permissions by a raw
 * DB::table() insert, not Role::syncPermissions(), on purpose: syncPermissions()
 * rejects exactly this shape once D1.2 lands, so the only way left to build
 * the "already-violating" state this migration repairs is the same direct
 * write a pre-D1.2 caller (or the real roles.id=62 row) could have made.
 */
class RepairRoleDependencyViolationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_26_000001_repair_roles_missing_permission_dependencies.php';

    /** Each migration file's anonymous class is `require`d fresh per call — never cached across calls in one test. */
    private function freshMigration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function rawGrant(Role $role, string $permission): void
    {
        DB::table('role_permission')->insert([
            'role_id' => $role->id,
            'permission' => $permission,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_role_missing_its_dependency_gets_it_inserted(): void
    {
        $owner = User::factory()->create();
        $role = Role::create(['team_id' => $owner->current_team_id, 'slug' => 'billing-specialist', 'name' => 'Billing specialist']);
        $this->rawGrant($role, PermissionCatalog::BILLING_MANAGE);

        $this->freshMigration()->up();

        $this->assertEqualsCanonicalizing(
            [PermissionCatalog::BILLING_VIEW, PermissionCatalog::BILLING_MANAGE],
            $role->fresh()->permissionKeys(),
        );
    }

    /**
     * The known real instance this migration exists for: roles.id=62 on the
     * dev database held exactly this shape (team_id=14, billing-specialist).
     * Reproduced here as its own test so a regression in the repair logic
     * for this specific case is never masked by the more generic coverage
     * below.
     */
    public function test_the_confirmed_billing_specialist_shape_is_repaired(): void
    {
        $owner = User::factory()->create();
        $role = Role::create(['team_id' => $owner->current_team_id, 'slug' => 'billing-specialist', 'name' => 'Billing specialist']);
        $this->rawGrant($role, PermissionCatalog::BILLING_MANAGE);

        $this->freshMigration()->up();

        $this->assertContains(PermissionCatalog::BILLING_VIEW, $role->fresh()->permissionKeys());
    }

    public function test_a_role_already_satisfying_the_map_is_left_untouched(): void
    {
        $owner = User::factory()->create();
        $admin = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::ADMIN)->sole();
        $before = $admin->permissionKeys();

        $this->freshMigration()->up();

        $this->assertSame($before, $admin->fresh()->permissionKeys());
    }

    /**
     * Every entry in the dependency map gets the same treatment, not only
     * billing.* — built by granting each manage/invite/remove/create/etc.
     * permission to its own fresh role (never the dependency itself), then
     * checking the whole of PermissionCatalog::requires() is satisfied
     * afterward for every permission the role ended up holding.
     */
    public function test_repairs_every_entry_in_the_dependency_map_not_only_billing(): void
    {
        $owner = User::factory()->create();
        $nonViewPermissions = [
            PermissionCatalog::TEAM_SETTINGS_UPDATE, PermissionCatalog::TEAM_MEMBERS_INVITE,
            PermissionCatalog::TEAM_MEMBERS_REMOVE, PermissionCatalog::TEAM_ROLES_MANAGE,
            PermissionCatalog::AGENTS_CREATE, PermissionCatalog::AGENTS_UPDATE, PermissionCatalog::AGENTS_DELETE,
            PermissionCatalog::CATALOG_MANAGE, PermissionCatalog::CHANNELS_MANAGE,
            PermissionCatalog::CONVERSATIONS_REPLY, PermissionCatalog::CONVERSATIONS_TAKEOVER,
            PermissionCatalog::CRM_MANAGE, PermissionCatalog::ANALYTICS_MANAGE,
            PermissionCatalog::PROJECTS_CREATE, PermissionCatalog::PROJECTS_EDIT,
            PermissionCatalog::PROJECTS_ASSIGN, PermissionCatalog::PROJECTS_COMMENT, PermissionCatalog::PROJECTS_MANAGE,
        ];

        $roles = [];
        foreach ($nonViewPermissions as $i => $permission) {
            $role = Role::create(['team_id' => $owner->current_team_id, 'slug' => "map-check-{$i}", 'name' => "Map check {$i}"]);
            $this->rawGrant($role, $permission);
            $roles[$permission] = $role;
        }

        $this->freshMigration()->up();

        $requires = PermissionCatalog::requires();
        foreach ($roles as $permission => $role) {
            $keys = $role->fresh()->permissionKeys();
            foreach ($requires[$permission] as $dependency) {
                $this->assertContains($dependency, $keys, "{$permission} is still missing {$dependency}");
            }
        }
    }

    public function test_running_up_twice_changes_nothing(): void
    {
        $owner = User::factory()->create();
        $role = Role::create(['team_id' => $owner->current_team_id, 'slug' => 'billing-specialist-2', 'name' => 'Billing specialist 2']);
        $this->rawGrant($role, PermissionCatalog::BILLING_MANAGE);

        $this->freshMigration()->up();
        $before = DB::table('role_permission')->count();
        $beforeKeys = $role->fresh()->permissionKeys();

        $this->freshMigration()->up();

        $this->assertSame($before, DB::table('role_permission')->count());
        $this->assertSame($beforeKeys, $role->fresh()->permissionKeys());
    }
}
