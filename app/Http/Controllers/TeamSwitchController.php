<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches which team the signed-in user is currently looking at. Nothing
 * here changes what a user is ALLOWED to do once switched — only
 * `current_team_id` moves, which `User::activeTeamId()` and every
 * `scopeOwnedBy()` read for a LIST/CREATE check that has no bound resource
 * yet (see `RegistersTeamPermissions::teamId()`). A check against an
 * already-loaded model never consults this: every resource Policy resolves
 * team membership and permissions from the row's own `team_id`
 * (`TeamResolver::resolveTeamId()`), so switching the menu cannot change
 * whether an already-bound resource is authorized — that invariant is what
 * stops a stale or forged `current_team_id` from ever widening access to
 * something the switcher itself didn't open.
 */
class TeamSwitchController extends Controller
{
    /**
     * The posted `team_id` is never trusted on its own. `membershipIn()` —
     * the same check `activeTeamId()` relies on to keep a stale
     * `current_team_id` from ever leaking another team's rows — is what
     * actually decides membership here. A non-member id is a 403, not a
     * silently ignored no-op: the switcher's own menu never offers one, so
     * reaching here with one is either a stale page or a forged request,
     * neither of which should succeed quietly.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['team_id' => ['required', 'integer']]);

        abort_unless($request->user()->membershipIn($data['team_id']) !== null, 403);

        // forceFill: current_team_id is deliberately not mass-assignable on
        // User (see its #[Fillable] list) — no request payload should be
        // able to set it through anything but this one validated path.
        $request->user()->forceFill(['current_team_id' => $data['team_id']])->save();

        return back()->with('status', __('agents.nav.teams.switched'));
    }
}
