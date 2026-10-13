<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Stage 3c uses the same temporary owner check as invitations. The later
 * Policy/Gate stage will replace it; roles remain labels until then.
 */
class TeamMemberController extends Controller
{
    public function update(Request $request, TeamMembership $membership): RedirectResponse
    {
        $teamId = $this->ownedActiveTeamId($request);
        abort_unless((int) $membership->team_id === $teamId, 404);

        $data = $request->validateWithBag('team', [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('team_id', $teamId)],
        ]);

        $member = DB::transaction(function () use ($membership, $teamId, $data): User {
            $locked = TeamMembership::whereKey($membership->id)->where('team_id', $teamId)
                ->lockForUpdate()->firstOrFail();

            // Re-read inside the lock: an owner role assigned since the page
            // loaded must remain immune to a stale role-change submission.
            abort_if($locked->role->is_owner, 403);

            $locked->forceFill(['role_id' => $data['role_id']])->save();

            return $locked->user;
        });

        $member->flushPermissionCache();

        return back()->with('status', __('settings.team.member_role_saved'))->with('settings_tab', 'team');
    }

    public function destroy(Request $request, TeamMembership $membership, CreatesPersonalTeam $personalTeams): RedirectResponse
    {
        $teamId = $this->ownedActiveTeamId($request);
        abort_unless((int) $membership->team_id === $teamId, 404);

        $member = DB::transaction(function () use ($membership, $teamId, $personalTeams): User {
            $locked = TeamMembership::whereKey($membership->id)->where('team_id', $teamId)
                ->lockForUpdate()->firstOrFail();

            // The Owner role is the sole way to administer this team today;
            // removing or demoting it could leave the team unmanageable.
            abort_if($locked->role->is_owner, 403);

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

        return back()->with('status', __('settings.team.member_removed'))->with('settings_tab', 'team');
    }

    private function ownedActiveTeamId(Request $request): int
    {
        $user = $request->user();
        $teamId = $user->activeTeamId();

        abort_unless($teamId !== null && $user->isOwnerOf($teamId), 403);

        return $teamId;
    }
}
