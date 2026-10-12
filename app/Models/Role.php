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
     * @return array<string, self> role slug => the new team-scoped Role
     */
    public static function copyTemplatesFor(Team $team): array
    {
        $templates = static::whereNull('team_id')->get()->keyBy('slug');
        $roles = [];

        foreach (PermissionCatalog::systemRoleSlugs() as $slug) {
            $template = $templates->get($slug);

            $role = static::create([
                'team_id' => $team->id,
                'name' => PermissionCatalog::systemRoleName($slug),
                'slug' => $slug,
            ]);

            if ($slug === PermissionCatalog::OWNER) {
                $role->forceFill(['is_owner' => true])->save();
            }

            $role->syncPermissions($template?->permissionKeys() ?? PermissionCatalog::defaultMatrix()[$slug]);
            $roles[$slug] = $role;
        }

        return $roles;
    }
}
