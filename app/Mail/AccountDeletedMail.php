<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Tu cuenta fue eliminada": the last mail of an account, sent to the address she used before it is anonymized.
 * No action links, on purpose: the deletion cannot be undone from here.
 */
class AccountDeletedMail extends Mailable
{
    use Queueable;

    public string $firstName;

    public function __construct(User $user, public int $archivedEvents, public int $archiveDays)
    {
        // Plain values only: the mail is built after the user row has been anonymized.
        $this->firstName = MailLocale::firstName($user->name);
        $this->locale(MailLocale::forUser($user));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.account_deleted.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-deleted');
    }
}
