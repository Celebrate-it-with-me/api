<?php

namespace App\Mail;

use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the support mailbox that someone asked for help. Plain text, Spanish: it is read by the team. */
class SupportRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportRequest $supportRequest) {}

    public function envelope(): Envelope
    {
        $user = $this->supportRequest->user;

        return new Envelope(
            subject: "Solicitud de ayuda #{$this->supportRequest->id} de {$user->name}",
            replyTo: [new Address($user->email, $user->name)],
        );
    }

    public function content(): Content
    {
        $adminUrl = rtrim((string) config('services.support.admin_url'), '/');

        return new Content(
            text: 'emails.support-request',
            with: [
                'request' => $this->supportRequest,
                'user' => $this->supportRequest->user,
                'event' => $this->supportRequest->event,
                'context' => $this->supportRequest->context ?? [],
                'link' => $adminUrl !== '' ? "{$adminUrl}/support/{$this->supportRequest->id}" : null,
            ],
        );
    }
}
