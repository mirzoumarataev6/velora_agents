<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `PermissionCatalog::requires()`'s dependency map (manage needs view,
 * invite/remove need members.view, etc.) was enforced only at the HTTP
 * layer (`TeamRoleController::permissionRules()`'s validation closure)
 * until Stage D1 — `Role::syncPermissions()` never consulted it, so any
 * other write path to `role_permission` could leave a role violating it.
 * Confirmed real instance: `roles.id=62` (`team_id=14`, slug
 * `billing-specialist`) holds `billing.manage` without `billing.view`.
 *
 * This repairs every such row by INSERTing the missing dependency,
 * never by removing the permission the owner actually granted — silently
 * taking a right away is a worse surprise than granting the view half a
 * role was always supposed to carry alongside it. Permission names are
 * hardcoded rather than read from `PermissionCatalog::requires()`: a
 * migration is a sentence about the schema at the moment it was written,
 * and that map is application code that keeps changing after this file is
 * frozen — calling into it would make an old migration's behavior drift
 * with a catalog change nobody ran this file against.
 */
return new class extends Migration
{
    /** @var array<string, string> permission => the single dependency it requires, frozen at the time this migration was written */
    private const REQUIRES = [
        'team.settings.update' => 'team.settings.view',
        'team.members.invite' => 'team.members.view',
        'team.members.remove' => 'team.members.view',
        'team.roles.manage' => 'team.roles.view',
        'agents.create' => 'agents.view',
        'agents.update' => 'agents.view',
        'agents.delete' => 'agents.view',
        'catalog.manage' => 'catalog.view',
        'channels.manage' => 'channels.view',
        'conversations.reply' => 'conversations.view',
        'conversations.takeover' => 'conversations.view',
        'crm.manage' => 'crm.view',
        'analytics.manage' => 'analytics.view',
        'projects.create' => 'projects.view',
        'projects.edit' => 'projects.view',
        'projects.assign' => 'projects.view',
        'projects.comment' => 'projects.view',
        'projects.manage' => 'projects.view',
        'billing.manage' => 'billing.view',
    ];

    public function up(): void
    {
        $now = now();

        $rolesByPermission = DB::table('role_permission')
            ->whereIn('permission', array_keys(self::REQUIRES))
            ->select('role_id', 'permission')
            ->get()
            ->groupBy('permission');

        foreach (self::REQUIRES as $permission => $dependency) {
            foreach ($rolesByPermission->get($permission, collect()) as $row) {
                // insertOrIgnore against role_permission's own
                // unique(role_id, permission): a role that already holds
                // the dependency (the common case) costs a no-op insert,
                // not a duplicate-key error.
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $row->role_id,
                    'permission' => $dependency,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Empty, deliberately. There is no record of which dependency rows this
     * migration inserted versus which were already present before it ran —
     * rolling back by deleting would risk stripping a *.view permission a
     * role held independently of this repair. Leaving a role with a view
     * permission it did not strictly need is a far cheaper mistake than
     * leaving it unable to see what it can otherwise act on.
     */
    public function down(): void {}
};
