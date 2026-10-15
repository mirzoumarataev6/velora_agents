<?php

namespace Tests\Unit\Teams;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage D1.2: PermissionCatalog::requires() (manage needs view, etc.) used
 * to be enforced only by TeamRoleController's own validation closure.
 * Role::syncPermissions() is the last line of defence below every caller —
 * HTTP, tinker, a seeder, a future route — so it must reject the same
 * violating shape directly, with no HTTP layer involved at all.
 */
class RoleDependencyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function freshRole(): Role
    {
        $owner = User::factory()->create();

        return Role::create(['team_id' => $owner->current_team_id, 'slug' => 'custom-'.uniqid(), 'name' => 'Custom']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function dependencyMapProvider(): iterable
    {
        foreach (PermissionCatalog::requires() as $permission => $dependencies) {
            foreach ($dependencies as $dependency) {
                yield "{$permission} requires {$dependency}" => [$permission, $dependency];
            }
        }
    }

    #[DataProvider('dependencyMapProvider')]
    public function test_sync_permissions_throws_when_a_dependency_is_missing(string $permission, string $dependency): void
    {
        $role = $this->freshRole();

        $this->expectException(InvalidArgumentException::class);

        // The permission alone, deliberately never its dependency.
        $role->syncPermissions([$permission]);
    }

    #[DataProvider('dependencyMapProvider')]
    public function test_sync_permissions_accepts_the_permission_alongside_its_dependency(string $permission, string $dependency): void
    {
        $role = $this->freshRole();

        $role->syncPermissions([$dependency, $permission]);

        $this->assertEqualsCanonicalizing([$dependency, $permission], $role->permissionKeys());
    }

    public function test_sync_permissions_accepts_a_fully_valid_set(): void
    {
        $role = $this->freshRole();

        $role->syncPermissions(PermissionCatalog::all());

        $this->assertEqualsCanonicalizing(PermissionCatalog::all(), $role->permissionKeys());
    }

    public function test_sync_permissions_accepts_an_empty_set(): void
    {
        $role = $this->freshRole();

        $role->syncPermissions([]);

        $this->assertSame([], $role->permissionKeys());
    }

    public function test_sync_permissions_leaves_the_roles_prior_permissions_untouched_when_it_throws(): void
    {
        $role = $this->freshRole();
        $role->syncPermissions([PermissionCatalog::AGENTS_VIEW]);

        try {
            $role->syncPermissions([PermissionCatalog::BILLING_MANAGE]);
            $this->fail('Expected syncPermissions() to throw.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame([PermissionCatalog::AGENTS_VIEW], $role->fresh()->permissionKeys());
    }

    /**
     * copyTemplatesFor() bulk-inserts role_permission directly rather than
     * calling syncPermissions() per role (see its own docblock for why) —
     * which means the dependency check has to be re-applied there too, not
     * just assumed. Every slug's bundle, for a brand-new team, must satisfy
     * the map on its own.
     */
    public function test_copy_templates_for_produces_bundles_that_satisfy_the_dependency_map(): void
    {
        $team = Team::factory()->create();
        $roles = Role::copyTemplatesFor($team);

        $requires = PermissionCatalog::requires();
        foreach ($roles as $slug => $role) {
            $keys = $role->permissionKeys();
            foreach ($keys as $permission) {
                foreach ($requires[$permission] ?? [] as $dependency) {
                    $this->assertContains($dependency, $keys, "{$slug} has {$permission} without {$dependency}");
                }
            }
        }
    }
}
