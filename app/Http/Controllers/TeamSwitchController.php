<?php

namespace App\Http\Controllers;

use App\Services\Teams\HomeDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TeamSwitchController extends Controller
{
    public function update(Request $request, HomeDestination $destination): RedirectResponse
    {
        $data = $request->validate(['team_id' => ['required', 'integer']]);
        $user = $request->user();

        abort_unless($user->membershipIn($data['team_id']) !== null, 403);

        $user->forceFill(['current_team_id' => $data['team_id']])->save();
        $user->flushPermissionCache();

        return redirect($destination->url($user))->with('status', __('shell.nav.teams.switched'));
    }
}
