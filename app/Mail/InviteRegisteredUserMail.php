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

class InviteRegisteredUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public EventCollaborationInvite $invite;

    public function __construct(EventCollaborationInvite $invite)
    {
        $this->invite = $invite;
        // She already has an account: write in her language.
        $this->locale(MailLocale::forUser(User::query()->where('email', $invite->email)->first()));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.invite_registered.subject', ['event' => $this->invite->event->event_name]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invite-registered',
            with: [
                'invite' => $this->invite,
                'role' => MailLocale::role($this->invite->role, $this->locale),
                'eventUrl' => MailLinks::app("dashboard/events/{$this->invite->event_id}"),
            ],
        );
    }
}
