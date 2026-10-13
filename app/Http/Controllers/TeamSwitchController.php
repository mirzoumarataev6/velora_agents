<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches which team the signed-in user is currently looking at — read-only
 * groundwork (Stage 3a). Nothing here changes what a user is ALLOWED to do
 * once switched; it only moves `current_team_id`, which `User::activeTeamId()`
 * (Stage 2d-4) and every CRM `scopeOwnedBy()` already read. Policies/Gates
 * are untouched and still reduce to `user_id === $model->user_id` — that is
 * a later stage's job.
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
