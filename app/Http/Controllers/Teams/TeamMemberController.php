<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\AuthorizesRoleGrants;
use App\Services\Teams\CreatesPersonalTeam;
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

            // Re-read inside the lock: an owner role assigned since the page
            // loaded must remain immune to a stale role-change submission.
            abort_if($locked->role->is_owner, 403);

            $role = Role::where('team_id', $teamId)->whereKey($data['role_id'])->lockForUpdate()->firstOrFail();
            $grants = app(AuthorizesRoleGrants::class);
            abort_unless($grants->canGrant($request->user(), $locked->role)
                && $grants->canGrant($request->user(), $role), 403);
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

            // The Owner role is the sole way to administer this team today;
            // removing or demoting it could leave the team unmanageable.
            abort_if($locked->role->is_owner, 403);

            // Removing a member must demand the same authority granting
            // their role would — otherwise a custom role holding only
            // team.members.remove (and nothing else) could remove an Admin,
            // the exact escalation update() already closes for role changes.
            abort_unless(app(AuthorizesRoleGrants::class)->canGrant($request->user(), $locked->role), 403);

            $member = User::whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $locked->delete();

            if ((int) $member->current_team_id === $teamId) {
                $nextTeamId = TeamMembership::where('user_id', $member->id)
                    ->orderBy('id')->value('team_id');

                // A user created before personal-team backfill might have no
                // other membership. Give them one before updating navigation
                // state, so their next CRM read never lands on a stale team.
                $nextTeamId ??= $personalTeams->create($member)->id;
                $member->forceFill(['current_team_id' => $nextTeamId])->save();
            }

            return $member;
        });

        $member->flushPermissionCache();
        $request->user()->flushPermissionCache();

        return back()->with('status', __('settings.team.member_removed'))->with('settings_tab', 'team');
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
