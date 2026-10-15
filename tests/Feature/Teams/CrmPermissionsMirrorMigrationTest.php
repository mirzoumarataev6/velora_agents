<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `2026_10_25_000001` copies analytics.view/analytics.manage onto their new
 * crm.view/crm.manage twins for every role row, customized or not — see
 * that migration's own docblock for why it does NOT skip customized roles
 * the way `2026_10_23_000002` does.
 *
 * The global/system templates already carry crm.* by the time this suite's
 * RefreshDatabase finishes migrating (PermissionCatalog::defaultMatrix()
 * already includes it, and `2026_10_17_000008_seed_global_role_templates`
 * reads that live), so this migration is a true no-op for them in THIS
 * environment — it earns its keep on the real dev/production database,
 * which was migrated with the old catalog. What actually exercises the
 * copy here is a custom role built with an arbitrary, non-template
 * permission set, exactly like an owner's hand-picked role would be.
 */
class CrmPermissionsMirrorMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_25_000001_add_crm_permissions_mirroring_analytics.php';

    /** Each migration file's anonymous class is `require`d fresh per call — never cached across calls in one test. */
    private function freshMigration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function customRole(?int $teamId, array $permissions): Role
    {
        $role = Role::create([
            'team_id' => $teamId,
            'name' => 'Custom '.uniqid(),
            'slug' => 'custom-'.uniqid(),
        ]);
        $role->syncPermissions($permissions);

        return $role;
    }

    /**
     * Writes role_permission directly, bypassing Role::syncPermissions()'s
     * dependency check (added in Stage D1.2) — used only for the one
     * fixture below that deliberately builds the "invalid by that rule"
     * shape pre-existing data could hold (and this migration must mirror
     * as-is, not repair; repairing that shape is a separate migration,
     * 2026_10_26_000001).
     */
    private function customRoleBypassingDependencyCheck(?int $teamId, array $permissions): Role
    {
        $role = Role::create([
            'team_id' => $teamId,
            'name' => 'Custom '.uniqid(),
            'slug' => 'custom-'.uniqid(),
        ]);
        $now = now();
        DB::table('role_permission')->insert(array_map(
            fn (string $permission) => ['role_id' => $role->id, 'permission' => $permission, 'created_at' => $now, 'updated_at' => $now],
            $permissions,
        ));

        return $role;
    }

    public function test_a_role_with_only_analytics_view_gets_only_crm_view(): void
    {
        $owner = User::factory()->create();
        $role = $this->customRole($owner->current_team_id, [PermissionCatalog::ANALYTICS_VIEW]);

        $this->freshMigration()->up();

        $this->assertSame(
            [PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::CRM_VIEW],
            $role->permissionKeys(),
        );
    }

    public function test_a_role_with_only_analytics_manage_gets_only_crm_manage(): void
    {
        // Deliberately the "invalid by the new dependency rule" shape —
        // data that could already exist from before that rule did, which
        // the migration must still mirror exactly, not repair. Built via
        // the bypass helper: Role::syncPermissions() itself now rejects
        // this shape (Stage D1.2), so a raw insert is the only way left to
        // construct it.
        $owner = User::factory()->create();
        $role = $this->customRoleBypassingDependencyCheck($owner->current_team_id, [PermissionCatalog::ANALYTICS_MANAGE]);

        $this->freshMigration()->up();

        $this->assertSame(
            [PermissionCatalog::ANALYTICS_MANAGE, PermissionCatalog::CRM_MANAGE],
            $role->permissionKeys(),
        );
    }

    public function test_a_role_with_both_gets_both_and_a_role_with_neither_gets_neither(): void
    {
        $owner = User::factory()->create();
        $both = $this->customRole($owner->current_team_id, [PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE]);
        $neither = $this->customRole($owner->current_team_id, [PermissionCatalog::AGENTS_VIEW]);

        $this->freshMigration()->up();

        $this->assertEqualsCanonicalizing(
            [PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE, PermissionCatalog::CRM_VIEW, PermissionCatalog::CRM_MANAGE],
            $both->permissionKeys(),
        );
        $this->assertSame([PermissionCatalog::AGENTS_VIEW], $neither->permissionKeys());
    }

    public function test_covers_global_templates_system_roles_and_custom_roles_alike(): void
    {
        $owner = User::factory()->create();
        $otherTeam = Team::factory()->create();
        $customOnOtherTeam = $this->customRole($otherTeam->id, [PermissionCatalog::ANALYTICS_VIEW]);

        $this->freshMigration()->up();

        // Global templates (team_id NULL): already seeded with crm.* by
        // the current PermissionCatalog, confirmed present rather than
        // assumed.
        foreach (PermissionCatalog::systemRoleSlugs() as $slug) {
            $template = Role::whereNull('team_id')->where('slug', $slug)->sole();
            $hadView = in_array(PermissionCatalog::ANALYTICS_VIEW, $template->permissionKeys(), true);
            $this->assertSame($hadView, in_array(PermissionCatalog::CRM_VIEW, $template->permissionKeys(), true), $slug);
        }

        // A per-team system role (copied from the template at team
        // creation).
        $admin = Role::where('team_id', $owner->current_team_id)->where('slug', PermissionCatalog::ADMIN)->sole();
        $this->assertContains(PermissionCatalog::CRM_VIEW, $admin->permissionKeys());
        $this->assertContains(PermissionCatalog::CRM_MANAGE, $admin->permissionKeys());

        // A custom role on a DIFFERENT team — not skipped, unlike the
        // projects migration.
        $this->assertContains(PermissionCatalog::CRM_VIEW, $customOnOtherTeam->permissionKeys());
    }

    public function test_running_up_twice_changes_nothing(): void
    {
        $owner = User::factory()->create();
        $role = $this->customRole($owner->current_team_id, [PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE]);

        $this->freshMigration()->up();
        $before = DB::table('role_permission')->count();
        $beforeKeys = $role->permissionKeys();

        $this->freshMigration()->up();

        $this->assertSame($before, DB::table('role_permission')->count());
        $this->assertSame($beforeKeys, $role->fresh()->permissionKeys());
    }

    public function test_down_deletes_every_crm_row_and_nothing_else(): void
    {
        $owner = User::factory()->create();
        $role = $this->customRole($owner->current_team_id, [PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE]);
        $this->freshMigration()->up();

        $this->freshMigration()->down();

        $this->assertSame(0, DB::table('role_permission')->whereIn('permission', [PermissionCatalog::CRM_VIEW, PermissionCatalog::CRM_MANAGE])->count());
        $this->assertSame(
            [PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE],
            $role->fresh()->permissionKeys(),
        );
    }
}
