<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Permissions\AuthorizesRoleGrants;
use App\Services\Teams\HomeDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamInvitationController extends Controller
{
    private const EXPIRES_AFTER_DAYS = 7;

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $teamId = $user->activeTeamId();

        $this->authorize('team.members.invite');
        abort_if($teamId === null, 403);

        abort_if(Team::whereKey($teamId)->value('is_personal'), 403);

        $data = $request->validateWithBag('team', [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('team_id', $teamId)],
        ]);

        $role = Role::where('team_id', $teamId)->findOrFail($data['role_id']);
        abort_unless(app(AuthorizesRoleGrants::class)->canGrant($user, $role), 403);

        $alreadyPending = Invitation::where('team_id', $teamId)
            ->where('email', $data['email'])
            ->where('status', Invitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->exists();

        if ($alreadyPending) {
            throw ValidationException::withMessages([
                'email' => __('settings.team.invite_already_pending'),
            ])->errorBag('team');
        }

        $invitation = Invitation::create([
            'team_id' => $teamId,
            'email' => $data['email'],
            'role_id' => $data['role_id'],
            'invited_by' => $user->id,
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
        ]);

        Mail::to($invitation->email)->locale(app()->getLocale())->queue(new TeamInvitationMail($invitation));

        return back()->with('status', __('settings.team.invite_sent'));
    }

    public function destroy(Request $request, Invitation $invitation): RedirectResponse
    {
        $this->authorize('team.members.invite', $invitation);

        abort_if($invitation->role === null, 404);

        abort_unless(app(AuthorizesRoleGrants::class)->canGrant($request->user(), $invitation->role), 403);

        if ($invitation->status === Invitation::STATUS_PENDING) {
            $invitation->forceFill(['status' => Invitation::STATUS_REVOKED])->save();
        }

        return back()->with('status', __('settings.team.invite_revoked'));
    }

    public function accept(Request $request, string $token, HomeDestination $destination): Response|RedirectResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if ($invitation === null) {
            return $this->errorPage('settings.team.accept_not_found', 404);
        }

        if ($invitation->status === Invitation::STATUS_ACCEPTED) {
            return $this->errorPage('settings.team.accept_already_used', 409);
        }

        if ($invitation->status !== Invitation::STATUS_PENDING || $invitation->expires_at->isPast()) {
            return $this->errorPage('settings.team.accept_expired', 410);
        }

        if (! $request->user()) {

            return redirect()->guest(route('login'));
        }

        $user = $request->user();

        if (mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            return $this->errorPage('settings.team.accept_email_mismatch', 403, ['email' => $invitation->email]);
        }

        abort_unless(Role::where('team_id', $invitation->team_id)->whereKey($invitation->role_id)->exists(), 403);

        if ($user->membershipIn($invitation->team_id) === null) {
            TeamMembership::create([
                'team_id' => $invitation->team_id,
                'user_id' => $user->id,
                'role_id' => $invitation->role_id,
                'joined_at' => now(),
            ]);
            $user->flushPermissionCache();
        }

        $invitation->forceFill(['status' => Invitation::STATUS_ACCEPTED, 'accepted_at' => now()])->save();

        $user->forceFill(['current_team_id' => $invitation->team_id])->save();

        return redirect()->to($destination->url($user))->with('status', __('settings.team.accept_success', ['team' => $invitation->team->name]));
    }

    private function errorPage(string $key, int $status, array $replace = []): Response
    {
        return response()->view('invitations.error', [
            'message' => __($key, $replace),
        ], $status);
    }
}
