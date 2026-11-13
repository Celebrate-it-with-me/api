<?php

namespace App\Mail;

use App\Models\Events;
use App\Models\User;
use App\Support\Mail\MailLinks;
use App\Support\Mail\MailLocale;
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
    public function __construct(public Events $event, public User $user, public string $side)
    {
        $this->locale(MailLocale::forUser($user));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("mail.ownership_{$this->side}.subject", ['event' => $this->event->event_name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ownership-transferred', with: [
            'eventUrl' => MailLinks::app("dashboard/events/{$this->event->id}"),
        ]);
    }
}
