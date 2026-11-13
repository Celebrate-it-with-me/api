<?php

namespace App\Mail;

use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConfirmAccountEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $confirmUrl;

    public $user;

    public function __construct($user, $confirmUrl)
    {
        $this->user = $user;
        $this->confirmUrl = $confirmUrl;
        $this->locale(MailLocale::forUser($user));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.confirm.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.confirm');
    }
}
