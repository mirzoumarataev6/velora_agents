<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Team;
use App\Services\Permissions\AuthorizesRoleGrants;
use App\Services\Permissions\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TeamRoleController extends Controller
{
    public function index(Request $request): View
    {
        $teamId = $this->permittedActiveTeamId($request, 'team.roles.view');
        $editId = $request->query('edit');
        abort_if($editId !== null && (! is_scalar($editId) || ! ctype_digit((string) $editId)), 404);
        $selectedRole = $editId !== null
            ? Role::where('team_id', $teamId)->findOrFail((int) $editId)
            : null;

        return view('teams.roles.index', [
            'i18nExtraNamespaces' => ['settings'],
            'roles' => Role::where('team_id', $teamId)->orderBy('id')->get(),
            'selectedRole' => $selectedRole,
            'selectedPermissions' => $selectedRole?->is_owner
                ? PermissionCatalog::all()
                : ($selectedRole?->permissionKeys() ?? []),
            'permissionGroups' => collect(PermissionCatalog::all())
                ->groupBy(fn (string $permission) => explode('.', $permission)[0]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $teamId = $this->permittedActiveTeamId($request, 'team.roles.manage');
        $data = $request->validateWithBag('roles', [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('team_id', $teamId)],
            ...$this->permissionRules(),
        ]);

        abort_unless(app(AuthorizesRoleGrants::class)->canGrantPermissions($request->user(), $teamId, $data['permissions'] ?? []), 403);

        $role = DB::transaction(function () use ($teamId, $data): Role {

            $base = Str::substr(Str::slug($data['name']) ?: 'role', 0, 230);
            $role = Role::create([
                'team_id' => $teamId,
                'name' => $data['name'],
                'slug' => $base.'-'.Str::lower(Str::random(12)),
            ]);
            $role->syncPermissions($data['permissions'] ?? []);

            return $role;
        });

        return redirect()->route('teams.roles.index', ['edit' => $role->id])
            ->with('status', __('settings.roles.created'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $teamId = $this->permittedActiveTeamId($request, 'team.roles.manage');
        abort_unless((int) $role->team_id === $teamId, 404);
        abort_if($role->is_owner, 403);

        $isSystem = in_array($role->slug, PermissionCatalog::systemRoleSlugs(), true);
        $data = $request->validateWithBag('roles', [

            'name' => $isSystem
                ? ['prohibited']
                : ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('team_id', $teamId)->ignore($role->id)],
            ...$this->permissionRules(),
        ]);

        DB::transaction(function () use ($request, $role, $teamId, $isSystem, $data): void {
            $locked = Role::whereKey($role->id)->where('team_id', $teamId)->lockForUpdate()->firstOrFail();
            abort_if($locked->is_owner, 403);
            $grants = app(AuthorizesRoleGrants::class);
            abort_unless($grants->canGrant($request->user(), $locked)
                && $grants->canGrantPermissions($request->user(), $teamId, $data['permissions'] ?? []), 403);

            if (! $isSystem) {
                $locked->forceFill(['name' => $data['name']])->save();
            }

            $locked->syncPermissions($data['permissions'] ?? []);
        });

        $request->user()->flushPermissionCache();

        return redirect()->route('teams.roles.index', ['edit' => $role->id])
            ->with('status', __('settings.roles.saved'));
    }

    private function permissionRules(): array
    {
        return [

            'permissions' => ['sometimes', 'array', function (string $attribute, mixed $value, \Closure $fail): void {
                $selected = (array) $value;
                foreach (PermissionCatalog::requires() as $permission => $dependencies) {
                    if (! in_array($permission, $selected, true)) {
                        continue;
                    }
                    foreach ($dependencies as $dependency) {
                        if (! in_array($dependency, $selected, true)) {
                            $fail(__('settings.roles.validation.requires', [
                                'permission' => __('settings.roles.permissions.'.$permission),
                                'dependency' => __('settings.roles.permissions.'.$dependency),
                            ]));
                        }
                    }
                }
            }],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all()), 'distinct'],
        ];
    }

    private function permittedActiveTeamId(Request $request, string $permission): int
    {
        $user = $request->user();
        $teamId = $user->activeTeamId();

        $this->authorize($permission);
        abort_if($teamId === null, 403);
        abort_if(Team::whereKey($teamId)->value('is_personal'), 403);

        return $teamId;
    }
}
