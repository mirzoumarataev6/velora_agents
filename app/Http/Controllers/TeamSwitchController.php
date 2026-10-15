<?php

namespace App\Http\Controllers;

use App\Services\Teams\HomeDestination;
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
    public function update(Request $request, HomeDestination $destination): RedirectResponse
    {
        $data = $request->validate(['team_id' => ['required', 'integer']]);
        $user = $request->user();

        abort_unless($user->membershipIn($data['team_id']) !== null, 403);

        // forceFill: current_team_id is deliberately not mass-assignable on
        // User (see its #[Fillable] list) — no request payload should be
        // able to set it through anything but this one validated path.
        $user->forceFill(['current_team_id' => $data['team_id']])->save();
        $user->flushPermissionCache();

        // D-02/U-02: always the new team's home, never back() to wherever
        // the user was. This used to try to distinguish a handful of known
        // "section index" routes (back() was fine) from everything else
        // (assumed fine too, by default) — but a detail page bound to a
        // specific row (/agents/{id}, /crm/deals/{id},
        // /projects/boards/{id}, a conversation thread) belongs to the OLD
        // team by construction: every such policy keys off the ROW's own
        // team_id (see this class's own docblock), not the active team, so
        // the row almost never exists under the new one. back() into it
        // after a switch 404s at best; at worst, a colliding id under the
        // new team silently shows the wrong team's row. There is no fixed
        // list of "deep" routes to maintain here — any page bound to a
        // specific record is a candidate, and guessing which ones happen
        // to survive a switch is a losing game. Linear and GitHub both make
        // the same call: switching context always lands on that context's
        // home. The real cost, paid on every switch: the user loses their
        // place in whatever they were looking at, even on a page the new
        // role could still have opened.
        return redirect($destination->url($user))->with('status', __('agents.nav.teams.switched'));
    }
}
