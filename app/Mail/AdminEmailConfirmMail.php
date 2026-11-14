<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the NEW address when a staff member asks to change her sign-in email; the link confirms the change. */
class AdminEmailConfirmMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $lang;

    public function __construct(public User $user, public string $link, public int $minutes)
    {
        $this->lang = MailLocale::forUser($user);
        $this->locale($this->lang);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.admin_email_confirm.subject'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.admin-email-confirm',
            with: ['name' => $this->user->name, 'link' => $this->link, 'minutes' => $this->minutes],
        );
    }
}
