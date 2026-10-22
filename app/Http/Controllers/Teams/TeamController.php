<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Permissions\AuthorizesRoleGrants;
use App\Services\Teams\CreatesTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $team = Team::find($user->activeTeamId());
        $collaborative = $team !== null && ! $team->is_personal;
        $canViewMembers = $collaborative && $user->can('team.members.view');
        $canInvite = $collaborative && $user->can('team.members.invite');
        $canManageRoles = $collaborative && $user->can('team.roles.manage');

        $teamRoles = ($canInvite || $canManageRoles)
            ? Role::where('team_id', $team->id)->orderBy('id')->get()
                ->filter(fn (Role $role) => app(AuthorizesRoleGrants::class)->canGrant($user, $role))
            : collect();

        return view('teams.index', [
            'i18nExtraNamespaces' => ['settings'],
            'activeTeam' => $team,
            'canViewTeam' => $collaborative && ($canViewMembers || $canInvite || $user->can('team.roles.view')),
            'teamRoles' => $teamRoles,

            'defaultInviteRoleId' => $teamRoles->reject(fn (Role $role) => $role->is_owner)
                ->sortBy(fn (Role $role) => count($role->permissionKeys()))
                ->first()?->id ?? $teamRoles->first()?->id,
            'teamMembers' => $canViewMembers
                ? TeamMembership::where('team_id', $team->id)->with(['user', 'role'])->orderBy('id')->get()
                : collect(),
            'pendingInvitations' => $canInvite
                ? Invitation::where('team_id', $team->id)->where('status', Invitation::STATUS_PENDING)
                    ->with(['role', 'inviter'])->orderByDesc('created_at')->get()
                : collect(),
        ]);
    }

    public function store(Request $request, CreatesTeam $teams): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validateWithBag('createTeam', [
            'name' => ['required', 'string', 'max:255'],
        ]);

        $teams->create($request->user(), trim($data['name']));

        return redirect()->route('teams.index')
            ->with('status', __('settings.team.created'));
    }
}
