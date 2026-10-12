<?php

use App\Services\Permissions\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 4 global role templates (team_id NULL: owner/admin/operator/
 * viewer) and their default permission bundles from the code-defined
 * catalog. Every real team's roles are copied from these at team-creation
 * time (Role::copyTemplatesFor) — these template rows are never assigned to
 * a team_user row directly.
 *
 * Uses the query builder rather than the Eloquent models, as elsewhere in
 * this migration directory, so this migration keeps behaving the same way
 * if the models' shape changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (PermissionCatalog::systemRoleSlugs() as $slug) {
            $roleId = DB::table('roles')->insertGetId([
                'team_id' => null,
                'name' => PermissionCatalog::systemRoleName($slug),
                'slug' => $slug,
                'is_owner' => $slug === PermissionCatalog::OWNER,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $permissions = PermissionCatalog::defaultMatrix()[$slug];

            DB::table('role_permission')->insert(array_map(
                fn (string $permission) => [
                    'role_id' => $roleId,
                    'permission' => $permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $permissions,
            ));
        }
    }

    public function down(): void
    {
        DB::table('roles')->whereNull('team_id')->delete();
        // role_permission rows cascade-delete with their role.
    }
};
