<?php

namespace App\Services\Permissions;

use InvalidArgumentException;

/**
 * The full set of permissions a role can be built from. Deliberately a
 * fixed, code-defined catalog rather than a database table: permissions
 * never vary per tenant and are never user-creatable, so there is nothing
 * here that needs its own seed/migration-drift story. What IS per-team
 * editable state is which of these keys a given role holds — see
 * `Role::syncPermissions()`.
 */
final class PermissionCatalog
{
    // Team settings
    public const TEAM_SETTINGS_VIEW = 'team.settings.view';

    public const TEAM_SETTINGS_UPDATE = 'team.settings.update';

    public const TEAM_DELETE = 'team.delete';

    // Members
    public const TEAM_MEMBERS_VIEW = 'team.members.view';

    public const TEAM_MEMBERS_INVITE = 'team.members.invite';

    public const TEAM_MEMBERS_REMOVE = 'team.members.remove';

    // Roles
    public const TEAM_ROLES_VIEW = 'team.roles.view';

    public const TEAM_ROLES_MANAGE = 'team.roles.manage';

    // Agents
    public const AGENTS_VIEW = 'agents.view';

    public const AGENTS_CREATE = 'agents.create';

    public const AGENTS_UPDATE = 'agents.update';

    public const AGENTS_DELETE = 'agents.delete';

    // Catalog (datasets, products, images)
    public const CATALOG_VIEW = 'catalog.view';

    public const CATALOG_MANAGE = 'catalog.manage';

    // Channels
    public const CHANNELS_VIEW = 'channels.view';

    public const CHANNELS_MANAGE = 'channels.manage';

    // Conversations / human takeover
    public const CONVERSATIONS_VIEW = 'conversations.view';

    public const CONVERSATIONS_REPLY = 'conversations.reply';

    public const CONVERSATIONS_TAKEOVER = 'conversations.takeover';

    // CRM (deals, customers, tasks, notes, stages, workspaces) — split out
    // of analytics.* so a role can be given the CRM without also getting
    // BI: the two have different audiences (whoever works the pipeline vs.
    // whoever reads the numbers) and, for BI specifically, different risk
    // (external DB connections, uploads) that shouldn't ride along with
    // CRM access by default.
    public const CRM_VIEW = 'crm.view';

    public const CRM_MANAGE = 'crm.manage';

    // Analytics (BI only, see above)
    public const ANALYTICS_VIEW = 'analytics.view';

    public const ANALYTICS_MANAGE = 'analytics.manage';

    // Project boards and shared task work
    public const PROJECTS_VIEW = 'projects.view';

    public const PROJECTS_CREATE = 'projects.create';

    public const PROJECTS_EDIT = 'projects.edit';

    public const PROJECTS_ASSIGN = 'projects.assign';

    public const PROJECTS_COMMENT = 'projects.comment';

    public const PROJECTS_MANAGE = 'projects.manage';

    // Billing — gates exist even though no billing UI is built yet.
    public const BILLING_VIEW = 'billing.view';

    public const BILLING_MANAGE = 'billing.manage';

    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const OPERATOR = 'operator';

    public const VIEWER = 'viewer';

    /** @return list<string> every permission key that exists, in catalog order */
    public static function all(): array
    {
        return [
            self::TEAM_SETTINGS_VIEW, self::TEAM_SETTINGS_UPDATE, self::TEAM_DELETE,
            self::TEAM_MEMBERS_VIEW, self::TEAM_MEMBERS_INVITE, self::TEAM_MEMBERS_REMOVE,
            self::TEAM_ROLES_VIEW, self::TEAM_ROLES_MANAGE,
            self::AGENTS_VIEW, self::AGENTS_CREATE, self::AGENTS_UPDATE, self::AGENTS_DELETE,
            self::CATALOG_VIEW, self::CATALOG_MANAGE,
            self::CHANNELS_VIEW, self::CHANNELS_MANAGE,
            self::CONVERSATIONS_VIEW, self::CONVERSATIONS_REPLY, self::CONVERSATIONS_TAKEOVER,
            self::CRM_VIEW, self::CRM_MANAGE,
            self::ANALYTICS_VIEW, self::ANALYTICS_MANAGE,
            self::PROJECTS_VIEW, self::PROJECTS_CREATE, self::PROJECTS_EDIT,
            self::PROJECTS_ASSIGN, self::PROJECTS_COMMENT, self::PROJECTS_MANAGE,
            self::BILLING_VIEW, self::BILLING_MANAGE,
        ];
    }

    public static function assertValid(string $permission): void
    {
        if (! in_array($permission, self::all(), true)) {
            throw new InvalidArgumentException("Unknown permission: {$permission}");
        }
    }

    /**
     * A permission that is meaningless without another. The general rule,
     * true of the whole catalog: every non-view permission is a write (or
     * invite/remove/manage) ability over rows its own section's `*.view` is
     * what lets a role read in the first place — a role that can
     * `agents.create` but not `agents.view` could make an agent it could
     * never afterward see. Enforced as a reject (`TeamRoleController`'s own
     * validation), never an auto-add on the server — silently inserting a
     * permission nobody selected is a worse surprise than telling the
     * owner "manage" needs "view" too. The role editor UI auto-checks/
     * unchecks driven by this exact map for the same reason convenience
     * belongs on the client: nobody should have to learn this rule by
     * hitting the rejection first.
     *
     * `team.delete` and `billing.view` carry no dependency: the former has
     * no `*.view` sibling to require (team membership itself is the only
     * "view" of a team), and the latter IS the view half of its own pair.
     *
     * @return array<string, list<string>> permission => the permissions it requires
     */
    public static function requires(): array
    {
        return [
            self::TEAM_SETTINGS_UPDATE => [self::TEAM_SETTINGS_VIEW],
            self::TEAM_MEMBERS_INVITE => [self::TEAM_MEMBERS_VIEW],
            self::TEAM_MEMBERS_REMOVE => [self::TEAM_MEMBERS_VIEW],
            self::TEAM_ROLES_MANAGE => [self::TEAM_ROLES_VIEW],
            self::AGENTS_CREATE => [self::AGENTS_VIEW],
            self::AGENTS_UPDATE => [self::AGENTS_VIEW],
            self::AGENTS_DELETE => [self::AGENTS_VIEW],
            self::CATALOG_MANAGE => [self::CATALOG_VIEW],
            self::CHANNELS_MANAGE => [self::CHANNELS_VIEW],
            self::CONVERSATIONS_REPLY => [self::CONVERSATIONS_VIEW],
            self::CONVERSATIONS_TAKEOVER => [self::CONVERSATIONS_VIEW],
            self::CRM_MANAGE => [self::CRM_VIEW],
            self::ANALYTICS_MANAGE => [self::ANALYTICS_VIEW],
            self::PROJECTS_CREATE => [self::PROJECTS_VIEW],
            self::PROJECTS_EDIT => [self::PROJECTS_VIEW],
            self::PROJECTS_ASSIGN => [self::PROJECTS_VIEW],
            self::PROJECTS_COMMENT => [self::PROJECTS_VIEW],
            self::PROJECTS_MANAGE => [self::PROJECTS_VIEW],
            self::BILLING_MANAGE => [self::BILLING_VIEW],
        ];
    }

    /** @return list<string> the 4 system role slugs, in seed order */
    public static function systemRoleSlugs(): array
    {
        return [self::OWNER, self::ADMIN, self::OPERATOR, self::VIEWER];
    }

    public static function systemRoleName(string $slug): string
    {
        return match ($slug) {
            self::OWNER => 'Owner',
            self::ADMIN => 'Admin',
            self::OPERATOR => 'Operator',
            self::VIEWER => 'Viewer',
            default => throw new InvalidArgumentException("Unknown system role: {$slug}"),
        };
    }

    /**
     * The default permission bundle for each of the 4 system roles, per the
     * role -> permission matrix in the Phase-1 plan. Owner's bundle is the
     * full catalog even though Owner access is actually granted via a
     * Gate::before bypass (added in a later stage) — seeding it keeps "what
     * can this role do" UI truthful and keeps the data consistent if the
     * bypass is ever removed.
     *
     * @return array<string, list<string>> role slug => permission keys
     */
    public static function defaultMatrix(): array
    {
        return [
            self::OWNER => self::all(),
            self::ADMIN => [
                self::TEAM_SETTINGS_VIEW, self::TEAM_SETTINGS_UPDATE,
                self::TEAM_MEMBERS_VIEW, self::TEAM_MEMBERS_INVITE, self::TEAM_MEMBERS_REMOVE,
                self::TEAM_ROLES_VIEW, self::TEAM_ROLES_MANAGE,
                self::AGENTS_VIEW, self::AGENTS_CREATE, self::AGENTS_UPDATE, self::AGENTS_DELETE,
                self::CATALOG_VIEW, self::CATALOG_MANAGE,
                self::CHANNELS_VIEW, self::CHANNELS_MANAGE,
                self::CONVERSATIONS_VIEW, self::CONVERSATIONS_REPLY, self::CONVERSATIONS_TAKEOVER,
                self::CRM_VIEW, self::CRM_MANAGE,
                self::ANALYTICS_VIEW, self::ANALYTICS_MANAGE,
                self::PROJECTS_VIEW, self::PROJECTS_CREATE, self::PROJECTS_EDIT,
                self::PROJECTS_ASSIGN, self::PROJECTS_COMMENT, self::PROJECTS_MANAGE,
                self::BILLING_VIEW,
            ],
            self::OPERATOR => [
                self::AGENTS_VIEW,
                self::CATALOG_VIEW,
                self::CHANNELS_VIEW,
                self::CONVERSATIONS_VIEW, self::CONVERSATIONS_REPLY, self::CONVERSATIONS_TAKEOVER,
                self::CRM_VIEW,
                self::ANALYTICS_VIEW,
                self::PROJECTS_VIEW, self::PROJECTS_CREATE, self::PROJECTS_EDIT,
                self::PROJECTS_ASSIGN, self::PROJECTS_COMMENT,
            ],
            self::VIEWER => [
                self::TEAM_MEMBERS_VIEW,
                self::TEAM_ROLES_VIEW,
                self::AGENTS_VIEW,
                self::CATALOG_VIEW,
                self::CHANNELS_VIEW,
                self::CONVERSATIONS_VIEW,
                self::CRM_VIEW,
                self::ANALYTICS_VIEW,
                self::PROJECTS_VIEW,
            ],
        ];
    }
}
