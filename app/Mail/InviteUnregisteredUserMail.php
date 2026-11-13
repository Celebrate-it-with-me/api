<?php

namespace App\Mail;

use App\Models\EventCollaborationInvite;
use App\Models\User;
use App\Support\Mail\MailLinks;
use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteUnregisteredUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public EventCollaborationInvite $invite;

    public function __construct(EventCollaborationInvite $invite)
    {
        $this->invite = $invite;
        // No account yet: the best guess is the language of whoever invited her.
        $this->locale(MailLocale::forUser($invite->invited_by_user_id ? User::query()->find($invite->invited_by_user_id) : null));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.invite_unregistered.subject', ['event' => $this->invite->event->event_name]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invite-unregistered',
            with: [
                'invite' => $this->invite,
                'role' => MailLocale::role($this->invite->role, $this->locale),
                'acceptUrl' => MailLinks::app("event/{$this->invite->event->id}/invite?token={$this->invite->token}"),
            ],
        );
    }
}
