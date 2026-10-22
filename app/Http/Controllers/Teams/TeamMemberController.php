<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\AuthorizesRoleGrants;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Teams\CreatesPersonalTeam;
use App\Services\Teams\HomeDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
    public function update(Request $request, TeamMembership $membership): RedirectResponse
    {
        $teamId = $this->permittedActiveTeamId($request, 'team.roles.manage');
        abort_unless((int) $membership->team_id === $teamId, 404);

        $data = $request->validateWithBag('team', [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('team_id', $teamId)],
        ]);

        $member = DB::transaction(function () use ($request, $membership, $teamId, $data): User {
            $locked = TeamMembership::whereKey($membership->id)->where('team_id', $teamId)
                ->lockForUpdate()->firstOrFail();

            abort_if($locked->role->is_owner, 403);

            $actor = $request->user();

            if (! $actor->isOwnerOf($teamId) && (int) $locked->user_id !== (int) $actor->id) {
                $managerPermissions = [PermissionCatalog::TEAM_MEMBERS_REMOVE, PermissionCatalog::TEAM_ROLES_MANAGE];
                abort_if(array_intersect($managerPermissions, $locked->role->permissionKeys()) !== [], 403);
            }

            $role = Role::where('team_id', $teamId)->whereKey($data['role_id'])->lockForUpdate()->firstOrFail();
            $grants = app(AuthorizesRoleGrants::class);
            abort_unless($grants->canGrant($actor, $locked->role)
                && $grants->canGrant($actor, $role), 403);
            $locked->forceFill(['role_id' => $role->id])->save();

            return $locked->user;
        });

        $member->flushPermissionCache();
        $request->user()->flushPermissionCache();

        return back()->with('status', __('settings.team.member_role_saved'))->with('settings_tab', 'team');
    }

    public function destroy(Request $request, TeamMembership $membership, CreatesPersonalTeam $personalTeams): RedirectResponse
    {
        $teamId = $this->permittedActiveTeamId($request, 'team.members.remove');
        abort_unless((int) $membership->team_id === $teamId, 404);

        $member = DB::transaction(function () use ($request, $membership, $teamId, $personalTeams): User {
            $locked = TeamMembership::whereKey($membership->id)->where('team_id', $teamId)
                ->lockForUpdate()->firstOrFail();

            abort_if($locked->role->is_owner, 403);

            $actor = $request->user();

            abort_if((int) $locked->user_id === (int) $actor->id, 403);

            if (! $actor->isOwnerOf($teamId)) {
                $managerPermissions = [PermissionCatalog::TEAM_MEMBERS_REMOVE, PermissionCatalog::TEAM_ROLES_MANAGE];
                abort_if(array_intersect($managerPermissions, $locked->role->permissionKeys()) !== [], 403);
            }

            abort_unless(app(AuthorizesRoleGrants::class)->canGrant($actor, $locked->role), 403);

            $member = User::whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $locked->delete();

            if ((int) $member->current_team_id === $teamId) {
                $nextTeamId = TeamMembership::where('user_id', $member->id)
                    ->orderBy('id')->value('team_id');

                $nextTeamId ??= $personalTeams->create($member)->id;
                $member->forceFill(['current_team_id' => $nextTeamId])->save();
            }

            return $member;
        });

        $member->flushPermissionCache();
        $request->user()->flushPermissionCache();

        return back()->with('status', __('settings.team.member_removed'))->with('settings_tab', 'team');
    }

    public function leave(Request $request, HomeDestination $destination, CreatesPersonalTeam $personalTeams): RedirectResponse
    {
        $user = $request->user();
        $teamId = $user->activeTeamId();
        abort_if($teamId === null, 403);

        $data = $request->validate(['team_id' => ['required', 'integer']]);

        abort_unless((int) $data['team_id'] === $teamId, 409, __('settings.team.leave_stale'));

        DB::transaction(function () use ($user, $teamId, $personalTeams): void {
            $locked = TeamMembership::where('user_id', $user->id)->where('team_id', $teamId)
                ->lockForUpdate()->first();
            abort_if($locked === null, 404);

            abort_if($locked->role->is_owner, 403, __('settings.team.leave_owner_blocked'));
            abort_if((bool) Team::whereKey($teamId)->value('is_personal'), 403, __('settings.team.leave_personal_blocked'));

            $locked->delete();

            $nextTeamId = TeamMembership::where('user_id', $user->id)
                ->orderBy('id')->value('team_id');
            $nextTeamId ??= $personalTeams->create($user)->id;
            $user->forceFill(['current_team_id' => $nextTeamId])->save();
        });

        $user->flushPermissionCache();

        return redirect($destination->url($user))->with('status', __('settings.team.left'));
    }

    private function permittedActiveTeamId(Request $request, string $permission): int
    {
        $user = $request->user();
        $teamId = $user->activeTeamId();

        $this->authorize($permission);
        abort_if($teamId === null, 403);

        return $teamId;
    }
}
