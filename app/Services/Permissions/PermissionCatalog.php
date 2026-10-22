<?php

namespace App\Services\Permissions;

use InvalidArgumentException;

final class PermissionCatalog
{
    public const TEAM_SETTINGS_VIEW = 'team.settings.view';

    public const TEAM_SETTINGS_UPDATE = 'team.settings.update';

    public const TEAM_DELETE = 'team.delete';

    public const TEAM_MEMBERS_VIEW = 'team.members.view';

    public const TEAM_MEMBERS_INVITE = 'team.members.invite';

    public const TEAM_MEMBERS_REMOVE = 'team.members.remove';

    public const TEAM_ROLES_VIEW = 'team.roles.view';

    public const TEAM_ROLES_MANAGE = 'team.roles.manage';

    public const AGENTS_VIEW = 'agents.view';

    public const AGENTS_CREATE = 'agents.create';

    public const AGENTS_UPDATE = 'agents.update';

    public const AGENTS_DELETE = 'agents.delete';

    public const CATALOG_VIEW = 'catalog.view';

    public const CATALOG_MANAGE = 'catalog.manage';

    public const CHANNELS_VIEW = 'channels.view';

    public const CHANNELS_MANAGE = 'channels.manage';

    public const CONVERSATIONS_VIEW = 'conversations.view';

    public const CONVERSATIONS_REPLY = 'conversations.reply';

    public const CONVERSATIONS_TAKEOVER = 'conversations.takeover';

    public const CRM_VIEW = 'crm.view';

    public const CRM_MANAGE = 'crm.manage';

    public const ANALYTICS_VIEW = 'analytics.view';

    public const ANALYTICS_MANAGE = 'analytics.manage';

    public const PROJECTS_VIEW = 'projects.view';

    public const PROJECTS_CREATE = 'projects.create';

    public const PROJECTS_EDIT = 'projects.edit';

    public const PROJECTS_ASSIGN = 'projects.assign';

    public const PROJECTS_COMMENT = 'projects.comment';

    public const PROJECTS_MANAGE = 'projects.manage';

    public const BILLING_VIEW = 'billing.view';

    public const BILLING_MANAGE = 'billing.manage';

    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const OPERATOR = 'operator';

    public const VIEWER = 'viewer';

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
