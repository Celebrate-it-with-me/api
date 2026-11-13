<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Mail\MailLinks;
use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->locale(MailLocale::forUser($user));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.welcome.subject', ['name' => MailLocale::firstName($this->user->name)]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.welcome', with: ['dashboardUrl' => MailLinks::app('dashboard')]);
    }
}
