<?php

namespace App\Mail;

use App\Models\EventCollaborationInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteUnregisteredUserMail extends Mailable
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
            view: 'emails.invite-unregistered-user',
            with: [
                'invite' => $this->invite,
                'acceptUrl' => config('app.frontend_app.url'). "/event/{$this->invite->event->id}/invite?token={$this->invite->token}",
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
