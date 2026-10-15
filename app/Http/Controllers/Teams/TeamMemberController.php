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

            // Re-read inside the lock: an owner role assigned since the page
            // loaded must remain immune to a stale role-change submission.
            abort_if($locked->role->is_owner, 403);

            $actor = $request->user();

            // H6, the same peer/manager hole destroy() has: canGrant() on
            // the CURRENT role passes trivially whenever the actor's own
            // bundle already matches it (an Admin targeting another Admin,
            // say), so without this a non-Owner could demote a fellow
            // manager — re-roling them is as much an escalation-shaped move
            // as removing them outright. Self-targeting is exempt on
            // purpose: self_role_change (see HasTeamsTest/this file's own
            // "self role change" test) legitimately lets a manager step
            // themselves down, and canGrant() on the NEW role already
            // blocks self-escalation (it can never hold a permission the
            // actor does not already have).
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

            // The Owner role is the sole way to administer this team today;
            // removing or demoting it could leave the team unmanageable.
            abort_if($locked->role->is_owner, 403);

            $actor = $request->user();

            // H6, self: destroy() is a removal endpoint, not a leave-team
            // one — a member who wants out needs a self-service `leave`
            // action (G-01), not this. An Owner removing themselves already
            // hits the is_owner check above; this closes the same door for
            // every other role until that action exists (Stage D2).
            abort_if((int) $locked->user_id === (int) $actor->id, 403);

            // H6, peer/manager: canGrant() alone is not the escalation guard
            // it looks like here. It passes trivially whenever the actor's
            // own role already holds every permission the TARGET role
            // holds — which is exactly true when an Admin targets another
            // Admin (same bundle), so an Admin could remove a peer Admin
            // today. Only the Owner may remove a member whose role carries
            // team.members.remove or team.roles.manage — the same authority
            // that would let THAT member remove or re-role others — so a
            // non-Owner can never use this endpoint to take out a fellow
            // manager.
            if (! $actor->isOwnerOf($teamId)) {
                $managerPermissions = [PermissionCatalog::TEAM_MEMBERS_REMOVE, PermissionCatalog::TEAM_ROLES_MANAGE];
                abort_if(array_intersect($managerPermissions, $locked->role->permissionKeys()) !== [], 403);
            }

            // Removing a member must demand the same authority granting
            // their role would — otherwise a custom role holding only
            // team.members.remove (and nothing else) could remove an Admin,
            // the exact escalation update() already closes for role changes.
            abort_unless(app(AuthorizesRoleGrants::class)->canGrant($actor, $locked->role), 403);

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

    /**
     * G-01: the self-service counterpart to destroy(), which blocks every
     * self-removal unconditionally (see its own comment — a leave action
     * was always the intended way out, not a gap left in that guard).
     * Deliberately ungated by any team.* permission: a member holding NONE
     * of them is exactly who needs this most, and nothing here reads or
     * writes another row — `$user->activeTeamId()` picks the team, there
     * is no {membership} route parameter or request field that could name
     * someone else's.
     */
    public function leave(Request $request, HomeDestination $destination, CreatesPersonalTeam $personalTeams): RedirectResponse
    {
        $user = $request->user();
        $teamId = $user->activeTeamId();
        abort_if($teamId === null, 403);

        DB::transaction(function () use ($user, $teamId, $personalTeams): void {
            $locked = TeamMembership::where('user_id', $user->id)->where('team_id', $teamId)
                ->lockForUpdate()->first();
            abort_if($locked === null, 404);

            // Re-read inside the lock, same discipline as update()/destroy():
            // a role granted Owner, or the team itself turning out to be the
            // one namespace this account cannot leave, must still be
            // honored by the time this commits, not just when the page
            // that posted here was rendered.
            abort_if($locked->role->is_owner, 403, __('settings.team.leave_owner_blocked'));
            abort_if((bool) Team::whereKey($teamId)->value('is_personal'), 403, __('settings.team.leave_personal_blocked'));

            $locked->delete();

            // Mirrors destroy()'s own reassignment exactly (earliest
            // membership by id, a fresh personal team only if truly none
            // remain) — never a second mechanism for "what team does this
            // user land on next." In practice this is always the personal
            // team: it is created first and so holds the lowest id.
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
