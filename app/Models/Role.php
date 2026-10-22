<?php

namespace App\Models;

use App\Services\Permissions\PermissionCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

#[Fillable(['team_id', 'name', 'slug'])]
class Role extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['is_owner' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function displayName(): string
    {
        return self::displayNameFor($this->slug, $this->name);
    }

    public static function displayNameFor(string $slug, string $name): string
    {

        $key = 'shell.nav.teams.roles.'.$slug;

        return Lang::has($key) ? __($key) : $name;
    }

    public function permissionKeys(): array
    {
        return DB::table('role_permission')->where('role_id', $this->id)->orderBy('id')->pluck('permission')->all();
    }

    public function hasPermission(string $permission): bool
    {
        PermissionCatalog::assertValid($permission);

        return in_array($permission, $this->permissionKeys(), true);
    }

    public function syncPermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            PermissionCatalog::assertValid($permission);
        }

        self::assertSatisfiesDependencies($permissions);

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

    private static function assertSatisfiesDependencies(array $permissions): void
    {
        foreach (PermissionCatalog::requires() as $permission => $dependencies) {
            if (! in_array($permission, $permissions, true)) {
                continue;
            }
            foreach ($dependencies as $dependency) {
                if (! in_array($dependency, $permissions, true)) {
                    throw new InvalidArgumentException(
                        "Permission set holds {$permission} without its required dependency {$dependency}."
                    );
                }
            }
        }
    }

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

            self::assertSatisfiesDependencies($permissions);

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
