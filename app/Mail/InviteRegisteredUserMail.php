<?php

namespace App\Mail;

use App\Models\EventCollaborationInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteRegisteredUserMail extends Mailable
{
    use Queueable, SerializesModels;
    
    public EventCollaborationInvite $invite;

    /**
     * Create a new message instance.
     */
    public function __construct(EventCollaborationInvite $invite)
    {
        $this->invite = $invite;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to collaborate on {$this->invite->event->event_name}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.invite-registered-user',
            with: [
                'invite' => $this->invite,
                'eventUrl' => config('app.frontend_app.url'). "dashboard/events/{$this->invite->event_id}",
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
