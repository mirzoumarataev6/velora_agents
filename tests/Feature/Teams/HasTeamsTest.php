<?php

namespace Tests\Feature\Teams;

use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The RBAC read side built in Stage 2b: `User::teams()`, `membershipIn()`,
 * `isOwnerOf()`, `hasPermissionOn()`, and the per-instance memoization that
 * backs all three. None of this is wired into any Policy or Gate yet —
 * these tests exercise the trait directly.
 *
 * Users are built with `createQuietly()` throughout, same reasoning as
 * Stage 2a's own team tests: a normal `create()` now gives every user an
 * unrelated personal team via UserObserver, which would be a second,
 * untested team sitting alongside the one each test deliberately builds.
 */
class HasTeamsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->createQuietly(['name' => 'Owner Olga']);
        $this->team = app(CreatesPersonalTeam::class)->create($this->owner);
    }

    private function roleOn(Team $team, string $slug): Role
    {
        return Role::where('team_id', $team->id)->where('slug', $slug)->firstOrFail();
    }

    private function addMember(Team $team, string $roleSlug, ?string $name = null): User
    {
        $user = User::factory()->createQuietly(['name' => $name ?? "{$roleSlug} member"]);

        TeamMembership::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role_id' => $this->roleOn($team, $roleSlug)->id,
            'joined_at' => now(),
        ]);

        return $user;
    }

    // --- Role matrix -------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function systemRoleProvider(): array
    {
        return [
            'owner' => [PermissionCatalog::OWNER],
            'admin' => [PermissionCatalog::ADMIN],
            'operator' => [PermissionCatalog::OPERATOR],
            'viewer' => [PermissionCatalog::VIEWER],
        ];
    }

    #[DataProvider('systemRoleProvider')]
    public function test_each_system_role_matches_the_catalog_default_matrix(string $slug): void
    {
        $member = $slug === PermissionCatalog::OWNER ? $this->owner : $this->addMember($this->team, $slug);
        $expected = PermissionCatalog::defaultMatrix()[$slug];

        foreach (PermissionCatalog::all() as $permission) {
            $this->assertSame(
                in_array($permission, $expected, true),
                $member->hasPermissionOn($this->team, $permission),
                "{$slug} and permission {$permission} disagree with the catalog default matrix"
            );
        }
    }

    public function test_owner_is_granted_every_permission_even_ones_outside_its_seeded_bundle(): void
    {
        // Owner's grant is the is_owner shortcut, not a lookup against its
        // permission rows — true even if that bundle were ever edited down.
        $this->roleOn($this->team, PermissionCatalog::OWNER)->syncPermissions([]);

        foreach (PermissionCatalog::all() as $permission) {
            $this->assertTrue($this->owner->hasPermissionOn($this->team, $permission));
        }
    }

    public function test_owner_membership_reports_is_owner(): void
    {
        $this->assertTrue($this->owner->isOwnerOf($this->team));

        $admin = $this->addMember($this->team, PermissionCatalog::ADMIN);
        $this->assertFalse($admin->isOwnerOf($this->team));
    }

    // --- Custom role ---------------------------------------------------

    public function test_a_custom_role_with_a_subset_of_permissions_grants_only_that_subset(): void
    {
        $custom = Role::create([
            'team_id' => $this->team->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);
        $custom->syncPermissions([PermissionCatalog::CONVERSATIONS_VIEW, PermissionCatalog::CONVERSATIONS_REPLY]);

        $user = User::factory()->createQuietly();
        TeamMembership::create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'role_id' => $custom->id,
            'joined_at' => now(),
        ]);

        $this->assertTrue($user->hasPermissionOn($this->team, PermissionCatalog::CONVERSATIONS_VIEW));
        $this->assertTrue($user->hasPermissionOn($this->team, PermissionCatalog::CONVERSATIONS_REPLY));
        $this->assertFalse($user->hasPermissionOn($this->team, PermissionCatalog::CONVERSATIONS_TAKEOVER));
        $this->assertFalse($user->hasPermissionOn($this->team, PermissionCatalog::AGENTS_VIEW));
        $this->assertFalse($user->isOwnerOf($this->team));
    }

    // --- Isolation -------------------------------------------------------

    public function test_a_non_member_is_denied_every_permission(): void
    {
        $stranger = User::factory()->createQuietly();

        $this->assertNull($stranger->membershipIn($this->team));
        $this->assertFalse($stranger->isOwnerOf($this->team));

        foreach (PermissionCatalog::all() as $permission) {
            $this->assertFalse($stranger->hasPermissionOn($this->team, $permission));
        }
    }

    public function test_a_member_of_team_a_is_denied_on_team_bs_resource(): void
    {
        $otherOwner = User::factory()->createQuietly(['name' => 'Other Owner']);
        $teamB = app(CreatesPersonalTeam::class)->create($otherOwner);

        // Owner of team A, asked about team B — not a member there at all.
        $this->assertNull($this->owner->membershipIn($teamB));
        $this->assertFalse($this->owner->isOwnerOf($teamB));
        $this->assertFalse($this->owner->hasPermissionOn($teamB, PermissionCatalog::AGENTS_VIEW));

        // And the reverse holds too.
        $this->assertFalse($otherOwner->hasPermissionOn($this->team, PermissionCatalog::AGENTS_VIEW));
    }

    public function test_a_null_team_is_denied_without_querying_anything(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertFalse($this->owner->hasPermissionOn(null, PermissionCatalog::AGENTS_VIEW));

        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_an_unknown_permission_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->owner->hasPermissionOn($this->team, 'not.a.real.permission');
    }

    // --- Memoization -----------------------------------------------------

    public function test_repeated_checks_on_the_same_team_cost_no_more_queries_than_the_first(): void
    {
        $admin = $this->addMember($this->team, PermissionCatalog::ADMIN);

        DB::flushQueryLog();
        DB::enableQueryLog();

        // First check warms the cache: membership + its role (2 queries),
        // plus the role's permission bundle for this first real permission
        // lookup (1 more) — 3 queries, not per-check.
        $admin->hasPermissionOn($this->team, PermissionCatalog::AGENTS_VIEW);
        $warmQueries = count(DB::getQueryLog());
        $this->assertLessThanOrEqual(3, $warmQueries, 'Warming the cache cost more than membership + role + permission set.');

        // 100 more checks, mixing isOwnerOf and every catalog permission,
        // all against the same team: must add zero further queries.
        for ($i = 0; $i < 100; $i++) {
            $admin->isOwnerOf($this->team);
            $admin->hasPermissionOn($this->team, PermissionCatalog::all()[$i % count(PermissionCatalog::all())]);
        }

        $this->assertCount($warmQueries, DB::getQueryLog(), '100 repeated checks added queries beyond the warm-up cost.');
        DB::disableQueryLog();
    }

    public function test_owners_checks_never_need_the_permission_set_query(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        // Owner short-circuits on role->is_owner, so only membership + role
        // is ever fetched (2 queries) — never the role_permission lookup.
        foreach (PermissionCatalog::all() as $permission) {
            $this->owner->hasPermissionOn($this->team, $permission);
        }

        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_flush_permission_cache_picks_up_a_role_permission_change(): void
    {
        $admin = $this->addMember($this->team, PermissionCatalog::ADMIN);
        $role = $this->roleOn($this->team, PermissionCatalog::ADMIN);

        $this->assertTrue($admin->hasPermissionOn($this->team, PermissionCatalog::BILLING_MANAGE) === false);
        $this->assertTrue($admin->hasPermissionOn($this->team, PermissionCatalog::AGENTS_VIEW));

        $role->syncPermissions([PermissionCatalog::BILLING_MANAGE]);

        // Stale answer: the cache from before the change still wins.
        $this->assertTrue($admin->hasPermissionOn($this->team, PermissionCatalog::AGENTS_VIEW));
        $this->assertFalse($admin->hasPermissionOn($this->team, PermissionCatalog::BILLING_MANAGE));

        $admin->flushPermissionCache();

        $this->assertFalse($admin->hasPermissionOn($this->team, PermissionCatalog::AGENTS_VIEW));
        $this->assertTrue($admin->hasPermissionOn($this->team, PermissionCatalog::BILLING_MANAGE));
    }

    public function test_flush_permission_cache_picks_up_a_role_reassignment(): void
    {
        $user = $this->addMember($this->team, PermissionCatalog::VIEWER);

        $this->assertFalse($user->hasPermissionOn($this->team, PermissionCatalog::AGENTS_CREATE));

        TeamMembership::where('team_id', $this->team->id)->where('user_id', $user->id)
            ->update(['role_id' => $this->roleOn($this->team, PermissionCatalog::ADMIN)->id]);

        // Stale: cached membership from before the reassignment still wins.
        $this->assertFalse($user->hasPermissionOn($this->team, PermissionCatalog::AGENTS_CREATE));

        $user->flushPermissionCache();

        $this->assertTrue($user->hasPermissionOn($this->team, PermissionCatalog::AGENTS_CREATE));
    }

    public function test_teams_relation_includes_role_id_on_the_pivot(): void
    {
        $team = $this->owner->teams()->first();

        $this->assertSame($this->team->id, $team->id);
        $this->assertSame($this->roleOn($this->team, PermissionCatalog::OWNER)->id, $team->pivot->role_id);
    }
}
