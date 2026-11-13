<?php

namespace App\Mail;

use App\Models\Events;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the new and the previous owner that the event changed hands (done by our team, on request). */
class OwnershipTransferredMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param 'new'|'previous' $side */
    public function __construct(public Events $event, public User $user, public string $side) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->side === 'new'
            ? "Ahora eres la dueña de {$this->event->event_name} / You are now the owner of {$this->event->event_name}"
            : "Cambio de titularidad de {$this->event->event_name} / Ownership change of {$this->event->event_name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ownership-transferred', with: [
            'eventUrl' => config('app.frontend_app.url') . "/dashboard/events/{$this->event->id}",
        ]);
    }
}
