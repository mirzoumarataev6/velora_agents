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
}
