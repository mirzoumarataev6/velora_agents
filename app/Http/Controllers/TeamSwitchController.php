<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Teams\HomeDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Throwable;

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

        // back() alone would happily return the user to the exact page they
        // switched FROM — fine when the new team's role can still open it,
        // a 403 the instant they arrive when it can't (a BI analyst role in
        // the old team switching into a CRM-only role in the new one, say).
        // Resolved through HomeDestination instead whenever that page is
        // one of the sections it knows how to gate and the new role can't
        // open it; every other page (nothing it recognizes) is assumed fine
        // to return to, same as today.
        if (! $this->previousPageStillOpen($request, $user, $destination)) {
            return redirect($destination->url($user))->with('status', __('agents.nav.teams.switched'));
        }

        return back()->with('status', __('agents.nav.teams.switched'));
    }

    private function previousPageStillOpen(Request $request, User $user, HomeDestination $destination): bool
    {
        $previous = $request->headers->get('referer');

        if ($previous === null) {
            return true;
        }

        try {
            $route = app(Router::class)->getRoutes()->match(Request::create($previous, 'GET'));
        } catch (Throwable) {
            // Not one of this app's own routes (or not a GET one) — nothing
            // this class knows how to re-check, so assumed fine.
            return true;
        }

        $name = $route->getName();

        return $name === null || $destination->stillOpen($user, $name);
    }
}
