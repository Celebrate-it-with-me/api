<?php

namespace App\Mail;

use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public $resetPasswordLink;

    public function __construct($user, $resetPasswordLink)
    {
        $this->user = $user;
        $this->resetPasswordLink = $resetPasswordLink;
        $this->locale(MailLocale::forUser($user));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.reset.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reset-password');
    }
}
