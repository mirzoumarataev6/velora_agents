<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once, by TeamInvitationController::store(). The accept link carries
 * the invitation's own `token` directly (see Invitation's own docblock on
 * why that column is a standalone value) — not a Laravel signed route — so
 * the link keeps working whether or not this mail is ever resent, and the
 * invitation itself can still be looked up, revoked or expire independently
 * of it.
 */
class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('settings.team.mail_subject', ['team' => $this->invitation->team->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invitation',
            with: [
                'teamName' => $this->invitation->team->name,
                'inviterName' => $this->invitation->inviter->name,
                'roleName' => __('agents.nav.teams.roles.'.$this->invitation->role->slug),
                'acceptUrl' => route('invitations.accept', $this->invitation->token),
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
