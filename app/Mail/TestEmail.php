<?php

namespace App\Mail;

use App\Support\Mail\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(string $lang = 'es')
    {
        $this->locale($lang);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.test.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.test', with: ['dashboardUrl' => MailLinks::app('dashboard')]);
    }
}
