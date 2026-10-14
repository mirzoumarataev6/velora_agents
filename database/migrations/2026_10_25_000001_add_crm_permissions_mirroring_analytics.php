<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stage B: `crm.view`/`crm.manage` are new, split out of `analytics.*` —
 * see `PermissionCatalog::CRM_VIEW`'s own docblock for why. Every role
 * that already holds `analytics.view`/`analytics.manage` gets the CRM
 * twin of whichever it has: a straight 1:1 copy of a grant that already
 * exists, not a new capability, since the CRM policies this stage moves
 * onto `crm.*` currently key off `analytics.*` — the role must keep the
 * key that check is about to read, or it silently loses the CRM it
 * already had the moment that policy switch lands.
 *
 * UNLIKE `2026_10_23_000002_add_project_permissions.php`, this does NOT
 * skip a role whose bundle was customized away from its system template.
 * That migration was granting a brand-new, previously nonexistent
 * capability (`projects.*`) and skipped customized roles so a deliberate
 * restriction wouldn't be silently widened. This migration is the
 * opposite: it is PRESERVING a capability (CRM access) a role already
 * has today, under the key its policy is about to actually check. A
 * customized role that was deliberately given `analytics.view` without
 * `analytics.manage` must end up with `crm.view` without `crm.manage` —
 * exactly mirroring what it already has, nothing more and nothing less —
 * so every role row is covered without exception: global templates
 * (`team_id` NULL), every team's system roles, and every custom role.
 *
 * Idempotent: `insertOrIgnore` against `role_permission`'s own
 * `unique(role_id, permission)` means running this twice changes nothing
 * the second time.
 */
return new class extends Migration
{
    /** @var array<string, string> analytics permission => its new CRM twin */
    private const MIRROR = [
        'analytics.view' => 'crm.view',
        'analytics.manage' => 'crm.manage',
    ];

    public function up(): void
    {
        $now = now();

        $rolesByPermission = DB::table('role_permission')
            ->whereIn('permission', array_keys(self::MIRROR))
            ->select('role_id', 'permission')
            ->get()
            ->groupBy('permission');

        foreach (self::MIRROR as $source => $target) {
            foreach ($rolesByPermission->get($source, collect()) as $row) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $row->role_id,
                    'permission' => $target,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Deletes every crm.* row, not only the ones this migration itself
     * inserted — same convention `2026_10_23_000002`'s own down() uses.
     * Never touches analytics.*, which this migration never wrote to.
     */
    public function down(): void
    {
        DB::table('role_permission')->whereIn('permission', array_values(self::MIRROR))->delete();
    }
};
