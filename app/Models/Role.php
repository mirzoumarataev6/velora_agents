<?php

namespace App\Models;

use App\Services\Permissions\PermissionCatalog;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A named bundle of permissions. `team_id` NULL marks one of the 4 global
 * system-role templates seeded once in a migration — never assigned to a
 * `team_user` row directly, only copied (see `copyTemplatesFor()`) into
 * real, per-team rows at team creation. `is_owner` marks the one role per
 * team that is immune to edit/delete: enforcement lives in a later stage's
 * Policy, not here.
 */
#[Fillable(['team_id', 'name', 'slug'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_owner' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * In catalog/insertion order (ordered by id), not alphabetically — so a
     * freshly copied role's bundle compares equal to `PermissionCatalog`'s
     * own ordering with a plain `===`, and a diff against it reads as "what
     * changed" rather than noise from an arbitrary re-sort.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        return DB::table('role_permission')->where('role_id', $this->id)->orderBy('id')->pluck('permission')->all();
    }

    public function hasPermission(string $permission): bool
    {
        PermissionCatalog::assertValid($permission);

        return in_array($permission, $this->permissionKeys(), true);
    }

    /** @param list<string> $permissions */
    public function syncPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            PermissionCatalog::assertValid($permission);
        }

        DB::table('role_permission')->where('role_id', $this->id)->delete();

        if ($permissions === []) {
            return;
        }

        $now = now();

        DB::table('role_permission')->insert(array_map(
            fn (string $permission) => [
                'role_id' => $this->id,
                'permission' => $permission,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $permissions,
        ));
    }

    /**
     * Copy the 4 global templates (team_id NULL) into real, team-scoped
     * rows for a freshly created team, each with its default permission
     * bundle.
     *
     * Bulk-inserted rather than one `create()` + one `syncPermissions()`
     * (a delete plus an insert) per role: since Stage 2a, this runs on
     * every single new user, not just real sign-ups, and the per-role
     * version's dozen-plus queries were a measurable chunk of the test
     * suite's wall time. Still 5 queries regardless of role count, not 1,
     * because MySQL's query builder `insert()` does not return the ids a
     * bulk insert created — a second `SELECT` is what gets them back for
     * `role_permission.role_id` to point at.
     *
     * @return array<string, self> role slug => the new team-scoped Role
     */
    public static function copyTemplatesFor(Team $team): array
    {
        $templates = static::whereNull('team_id')->get()->keyBy('slug');

        $templatePermissions = DB::table('role_permission')
            ->whereIn('role_id', $templates->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('permission')->all());

        $now = now();
        $slugs = PermissionCatalog::systemRoleSlugs();

        DB::table('roles')->insert(array_map(
            fn (string $slug) => [
                'team_id' => $team->id,
                'name' => PermissionCatalog::systemRoleName($slug),
                'slug' => $slug,
                'is_owner' => $slug === PermissionCatalog::OWNER ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $slugs,
        ));

        $roles = static::where('team_id', $team->id)->get()->keyBy('slug');

        $permissionRows = [];

        foreach ($slugs as $slug) {
            $template = $templates->get($slug);
            $permissions = $template !== null
                ? ($templatePermissions->get($template->id) ?? [])
                : PermissionCatalog::defaultMatrix()[$slug];

            foreach ($permissions as $permission) {
                $permissionRows[] = [
                    'role_id' => $roles->get($slug)->id,
                    'permission' => $permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($permissionRows !== []) {
            DB::table('role_permission')->insert($permissionRows);
        }

        return $roles->all();
    }
}
