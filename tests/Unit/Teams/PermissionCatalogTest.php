<?php

namespace Tests\Unit\Teams;

use App\Services\Permissions\PermissionCatalog;
use InvalidArgumentException;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_assert_valid_accepts_every_cataloged_permission(): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            PermissionCatalog::assertValid($permission);
        }

        $this->addToAssertionCount(1);
    }

    public function test_assert_valid_rejects_an_unknown_permission(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PermissionCatalog::assertValid('agents.launch_nukes');
    }

    public function test_owner_bundle_is_the_full_catalog(): void
    {
        $matrix = PermissionCatalog::defaultMatrix();

        $this->assertSame(PermissionCatalog::all(), $matrix[PermissionCatalog::OWNER]);
    }

    public function test_admin_has_everything_except_team_delete_and_billing_manage(): void
    {
        $admin = PermissionCatalog::defaultMatrix()[PermissionCatalog::ADMIN];

        $this->assertNotContains(PermissionCatalog::TEAM_DELETE, $admin);
        $this->assertNotContains(PermissionCatalog::BILLING_MANAGE, $admin);
        $this->assertContains(PermissionCatalog::BILLING_VIEW, $admin);
        $this->assertContains(PermissionCatalog::TEAM_MEMBERS_INVITE, $admin);
    }

    public function test_operator_can_act_on_conversations_but_not_manage_the_team(): void
    {
        $operator = PermissionCatalog::defaultMatrix()[PermissionCatalog::OPERATOR];

        $this->assertContains(PermissionCatalog::CONVERSATIONS_REPLY, $operator);
        $this->assertContains(PermissionCatalog::CONVERSATIONS_TAKEOVER, $operator);
        $this->assertNotContains(PermissionCatalog::TEAM_MEMBERS_VIEW, $operator);
        $this->assertNotContains(PermissionCatalog::AGENTS_CREATE, $operator);
    }

    public function test_viewer_is_view_only_everywhere_including_conversations(): void
    {
        $viewer = PermissionCatalog::defaultMatrix()[PermissionCatalog::VIEWER];

        foreach ($viewer as $permission) {
            $this->assertStringContainsString('.view', $permission, "{$permission} is not a *.view permission");
        }

        $this->assertNotContains(PermissionCatalog::CONVERSATIONS_REPLY, $viewer);
        $this->assertNotContains(PermissionCatalog::BILLING_VIEW, $viewer);
    }

    public function test_every_role_bundle_only_contains_cataloged_permissions(): void
    {
        foreach (PermissionCatalog::defaultMatrix() as $permissions) {
            foreach ($permissions as $permission) {
                PermissionCatalog::assertValid($permission);
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Stage B split crm.* out of analytics.*. Before this, Admin/Operator/
     * Viewer's bundles were exactly this (the pre-split hardcoded lists,
     * kept here only as a frozen snapshot — never update them to match a
     * future change, that would defeat the point). The only thing allowed
     * to differ now is the new crm.* pair, added everywhere
     * analytics.view/analytics.manage already was, nothing else touched
     * and nothing removed.
     */
    public function test_admin_operator_and_viewer_bundles_are_unchanged_except_for_the_new_crm_pair(): void
    {
        $oldAdmin = [
            PermissionCatalog::TEAM_SETTINGS_VIEW, PermissionCatalog::TEAM_SETTINGS_UPDATE,
            PermissionCatalog::TEAM_MEMBERS_VIEW, PermissionCatalog::TEAM_MEMBERS_INVITE, PermissionCatalog::TEAM_MEMBERS_REMOVE,
            PermissionCatalog::TEAM_ROLES_VIEW, PermissionCatalog::TEAM_ROLES_MANAGE,
            PermissionCatalog::AGENTS_VIEW, PermissionCatalog::AGENTS_CREATE, PermissionCatalog::AGENTS_UPDATE, PermissionCatalog::AGENTS_DELETE,
            PermissionCatalog::CATALOG_VIEW, PermissionCatalog::CATALOG_MANAGE,
            PermissionCatalog::CHANNELS_VIEW, PermissionCatalog::CHANNELS_MANAGE,
            PermissionCatalog::CONVERSATIONS_VIEW, PermissionCatalog::CONVERSATIONS_REPLY, PermissionCatalog::CONVERSATIONS_TAKEOVER,
            PermissionCatalog::ANALYTICS_VIEW, PermissionCatalog::ANALYTICS_MANAGE,
            PermissionCatalog::PROJECTS_VIEW, PermissionCatalog::PROJECTS_CREATE, PermissionCatalog::PROJECTS_EDIT,
            PermissionCatalog::PROJECTS_ASSIGN, PermissionCatalog::PROJECTS_COMMENT, PermissionCatalog::PROJECTS_MANAGE,
            PermissionCatalog::BILLING_VIEW,
        ];
        $oldOperator = [
            PermissionCatalog::AGENTS_VIEW,
            PermissionCatalog::CATALOG_VIEW,
            PermissionCatalog::CHANNELS_VIEW,
            PermissionCatalog::CONVERSATIONS_VIEW, PermissionCatalog::CONVERSATIONS_REPLY, PermissionCatalog::CONVERSATIONS_TAKEOVER,
            PermissionCatalog::ANALYTICS_VIEW,
            PermissionCatalog::PROJECTS_VIEW, PermissionCatalog::PROJECTS_CREATE, PermissionCatalog::PROJECTS_EDIT,
            PermissionCatalog::PROJECTS_ASSIGN, PermissionCatalog::PROJECTS_COMMENT,
        ];
        $oldViewer = [
            PermissionCatalog::TEAM_MEMBERS_VIEW,
            PermissionCatalog::TEAM_ROLES_VIEW,
            PermissionCatalog::AGENTS_VIEW,
            PermissionCatalog::CATALOG_VIEW,
            PermissionCatalog::CHANNELS_VIEW,
            PermissionCatalog::CONVERSATIONS_VIEW,
            PermissionCatalog::ANALYTICS_VIEW,
            PermissionCatalog::PROJECTS_VIEW,
        ];

        $matrix = PermissionCatalog::defaultMatrix();

        foreach ([
            PermissionCatalog::ADMIN => [$oldAdmin, [PermissionCatalog::CRM_VIEW, PermissionCatalog::CRM_MANAGE]],
            PermissionCatalog::OPERATOR => [$oldOperator, [PermissionCatalog::CRM_VIEW]],
            PermissionCatalog::VIEWER => [$oldViewer, [PermissionCatalog::CRM_VIEW]],
        ] as $role => [$old, $expectedAddition]) {
            $current = $matrix[$role];

            $this->assertSame([], array_values(array_diff($old, $current)), "{$role}: lost a pre-existing permission");
            $this->assertSame($expectedAddition, array_values(array_diff($current, $old)), "{$role}: gained something other than the new crm.* pair");
        }
    }

    /**
     * Stage C2: requires() generalizes from the two crm/analytics pairs to
     * every non-view permission in the catalog. Hardcoded (not a loop over
     * requires() itself) so this fails on a commit where the map is still
     * the narrower, pre-generalization version — a loop over requires()
     * would trivially pass against whatever the map happens to contain.
     */
    public function test_requires_covers_every_non_view_permission_in_the_catalog(): void
    {
        $requires = PermissionCatalog::requires();

        $this->assertSame([PermissionCatalog::TEAM_SETTINGS_VIEW], $requires[PermissionCatalog::TEAM_SETTINGS_UPDATE]);
        $this->assertSame([PermissionCatalog::TEAM_MEMBERS_VIEW], $requires[PermissionCatalog::TEAM_MEMBERS_INVITE]);
        $this->assertSame([PermissionCatalog::TEAM_MEMBERS_VIEW], $requires[PermissionCatalog::TEAM_MEMBERS_REMOVE]);
        $this->assertSame([PermissionCatalog::TEAM_ROLES_VIEW], $requires[PermissionCatalog::TEAM_ROLES_MANAGE]);
        $this->assertSame([PermissionCatalog::AGENTS_VIEW], $requires[PermissionCatalog::AGENTS_CREATE]);
        $this->assertSame([PermissionCatalog::AGENTS_VIEW], $requires[PermissionCatalog::AGENTS_UPDATE]);
        $this->assertSame([PermissionCatalog::AGENTS_VIEW], $requires[PermissionCatalog::AGENTS_DELETE]);
        $this->assertSame([PermissionCatalog::CATALOG_VIEW], $requires[PermissionCatalog::CATALOG_MANAGE]);
        $this->assertSame([PermissionCatalog::CHANNELS_VIEW], $requires[PermissionCatalog::CHANNELS_MANAGE]);
        $this->assertSame([PermissionCatalog::CONVERSATIONS_VIEW], $requires[PermissionCatalog::CONVERSATIONS_REPLY]);
        $this->assertSame([PermissionCatalog::CONVERSATIONS_VIEW], $requires[PermissionCatalog::CONVERSATIONS_TAKEOVER]);
        $this->assertSame([PermissionCatalog::PROJECTS_VIEW], $requires[PermissionCatalog::PROJECTS_CREATE]);
        $this->assertSame([PermissionCatalog::PROJECTS_VIEW], $requires[PermissionCatalog::PROJECTS_EDIT]);
        $this->assertSame([PermissionCatalog::PROJECTS_VIEW], $requires[PermissionCatalog::PROJECTS_ASSIGN]);
        $this->assertSame([PermissionCatalog::PROJECTS_VIEW], $requires[PermissionCatalog::PROJECTS_COMMENT]);
        $this->assertSame([PermissionCatalog::PROJECTS_VIEW], $requires[PermissionCatalog::PROJECTS_MANAGE]);
        $this->assertSame([PermissionCatalog::BILLING_VIEW], $requires[PermissionCatalog::BILLING_MANAGE]);
        // Unchanged from before this stage.
        $this->assertSame([PermissionCatalog::CRM_VIEW], $requires[PermissionCatalog::CRM_MANAGE]);
        $this->assertSame([PermissionCatalog::ANALYTICS_VIEW], $requires[PermissionCatalog::ANALYTICS_MANAGE]);
        // No dependency for either: team.delete has no *.view sibling, and
        // billing.view is the view half of its own pair.
        $this->assertArrayNotHasKey(PermissionCatalog::TEAM_DELETE, $requires);
        $this->assertArrayNotHasKey(PermissionCatalog::BILLING_VIEW, $requires);
    }

    /**
     * requires() is pointless as a server-side rejection rule if the
     * catalog's own seeded defaults could violate it — Owner, Admin,
     * Operator and Viewer must each already satisfy every dependency the
     * map declares, with no migration needed to bring them into line.
     */
    public function test_every_default_role_bundle_satisfies_the_dependency_map(): void
    {
        $requires = PermissionCatalog::requires();

        foreach (PermissionCatalog::defaultMatrix() as $role => $permissions) {
            foreach ($permissions as $permission) {
                foreach ($requires[$permission] ?? [] as $dependency) {
                    $this->assertContains(
                        $dependency,
                        $permissions,
                        "{$role} has {$permission} without its required {$dependency}",
                    );
                }
            }
        }
    }
}
