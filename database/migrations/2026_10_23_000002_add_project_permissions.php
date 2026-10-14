<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const GRANTS = [
        'owner' => ['projects.view', 'projects.create', 'projects.edit', 'projects.assign', 'projects.comment', 'projects.manage'],
        'admin' => ['projects.view', 'projects.create', 'projects.edit', 'projects.assign', 'projects.comment', 'projects.manage'],
        'operator' => ['projects.view', 'projects.create', 'projects.edit', 'projects.assign', 'projects.comment'],
        'viewer' => ['projects.view'],
    ];

    public function up(): void
    {
        $templates = DB::table('roles')->whereNull('team_id')->whereIn('slug', array_keys(self::GRANTS))->get()->keyBy('slug');
        $oldTemplateKeys = [];
        foreach ($templates as $slug => $template) {
            $oldTemplateKeys[$slug] = DB::table('role_permission')->where('role_id', $template->id)
                ->orderBy('permission')->pluck('permission')->all();
        }

        foreach (DB::table('roles')->whereIn('slug', array_keys(self::GRANTS))->get() as $role) {
            $oldKeys = DB::table('role_permission')->where('role_id', $role->id)
                ->orderBy('permission')->pluck('permission')->all();

            // Team owners can customize system roles. If their bundle
            // differs from the old template, adding rights silently could
            // expose the new project section to somebody they restricted.
            if ($role->team_id !== null && $role->slug !== 'owner'
                && $oldKeys !== ($oldTemplateKeys[$role->slug] ?? [])) {
                continue;
            }

            foreach (self::GRANTS[$role->slug] as $permission) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permission')->whereIn('permission', array_values(array_unique(array_merge(...array_values(self::GRANTS)))))->delete();
    }
};
