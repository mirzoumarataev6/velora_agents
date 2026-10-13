<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\Invitation;
use App\Models\TeamMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Invite someone by email to join the active team, and accept that
 * invitation. Stage 3b is explicitly scoped NOT to build a Policy class —
 * authorization for store()/destroy() below is a direct
 * `$user->isOwnerOf($team)` check, inline, the same TEMPORARY shape
 * TeamSwitchController already uses for its own membership check. This is
 * meant to be REPLACED, not extended: when a later stage wires
 * `Gate::before` for team permissions, these two checks are exactly what
 * it supersedes, alongside Stage 3c's member-management checks.
 */
class TeamInvitationController extends Controller
{
    /** Same window `Invitation`'s own factory default uses. */
    private const EXPIRES_AFTER_DAYS = 7;

    /**
     * The posted role_id is checked against THIS team specifically
     * (`Rule::exists('roles', 'id')->where('team_id', $teamId)`), not just
     * that a roles row with that id exists anywhere — a role belongs to
     * exactly one team, and accepting any id would let an owner hand out a
     * role from a team they do not even own.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $teamId = $user->activeTeamId();

        abort_unless($teamId !== null && $user->isOwnerOf($teamId), 403);

        $data = $request->validateWithBag('team', [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('team_id', $teamId)],
        ]);

        // Pending-and-unexpired only: an invitation that already expired, was
        // revoked, or was accepted leaves this email free to be invited
        // again — a resent invitation is deliberately a NEW row (see the
        // invitations migration's own docblock on why this is an index, not
        // a DB-level unique constraint).
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

        // ->locale(): this is queued, so by the time a worker renders it,
        // app()->getLocale() is whatever THAT process's default is, not this
        // request's — locking it in now is what keeps the email in the
        // owner's own language regardless.
        Mail::to($invitation->email)->locale(app()->getLocale())->queue(new TeamInvitationMail($invitation));

        return back()->with('status', __('settings.team.invite_sent'));
    }

    /**
     * Revoke a still-pending invitation. A no-op (not an error) on one
     * that's already accepted/expired/revoked — the settings page only
     * ever offers this button next to a still-pending row, so reaching
     * here otherwise is a stale page, not a real request to undo something.
     */
    public function destroy(Request $request, Invitation $invitation): RedirectResponse
    {
        abort_unless($request->user()->isOwnerOf($invitation->team_id), 403);

        if ($invitation->status === Invitation::STATUS_PENDING) {
            $invitation->forceFill(['status' => Invitation::STATUS_REVOKED])->save();
        }

        return back()->with('status', __('settings.team.invite_revoked'));
    }

    /**
     * The accept link. Carries the invitation's own `token` directly (see
     * `Invitation`'s own docblock) rather than a Laravel signed route.
     *
     * The invitation's own validity (found, not already used, not expired)
     * is checked BEFORE authentication: none of those three facts depends
     * on who is looking, and telling someone immediately that a link is
     * dead is kinder than making them sign in first to find out. Only once
     * it is confirmed live does this ask who is looking at all.
     */
    public function accept(Request $request, string $token): Response|RedirectResponse
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
            // Stores THIS url as url.intended; AuthController::login() and
            // ::register() both read it back once signed in — see the
            // latter's own fix (Stage 3b) for why that previously only
            // worked for an existing account.
            return redirect()->guest(route('login'));
        }

        $user = $request->user();

        // Case-insensitive, matching every other email comparison in this
        // codebase (e.g. SettingsController::destroy()'s confirmation
        // check) — an invite sent to Azim@example.com must still match a
        // session signed in as azim@example.com.
        if (mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            return $this->errorPage('settings.team.accept_email_mismatch', 403, ['email' => $invitation->email]);
        }

        // Already a member (a second click on the same link, or they joined
        // some other way since this was sent) — accept idempotently rather
        // than attempt a second insert team_user's own unique(team_id,
        // user_id) index would reject anyway.
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

        // "that team now active" — the whole point of accepting an invite
        // is to start working in it immediately, not to join it and keep
        // looking at whatever team happened to be current before.
        $user->forceFill(['current_team_id' => $invitation->team_id])->save();

        return redirect()->route('dashboard')->with('status', __('settings.team.accept_success', ['team' => $invitation->team->name]));
    }

    private function errorPage(string $key, int $status, array $replace = []): Response
    {
        return response()->view('invitations.error', [
            'message' => __($key, $replace),
        ], $status);
    }
}
