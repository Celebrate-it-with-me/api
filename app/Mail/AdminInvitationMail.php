<?php

namespace App\Mail;

use App\Models\AdminInvitation;
use App\Models\User;
use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to a person invited to the admin team; the link sets her password and, if required, her two-factor. */
class AdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $lang;

    /**
     * @param  User|null  $account  the existing Quinvite account of that address, if any (gives the language)
     */
    public function __construct(public AdminInvitation $invitation, public string $link, public string $inviter, public int $hours, ?User $account = null)
    {
        $this->lang = MailLocale::forUser($account);
        $this->locale($this->lang);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.admin_invitation.subject'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.admin-invitation',
            with: [
                'name' => $this->invitation->name ?: $this->invitation->email,
                'link' => $this->link,
                'inviter' => $this->inviter,
                'hours' => $this->hours,
                'role' => MailLocale::role('staff_' . $this->invitation->role, $this->lang),
            ],
        );
    }
}
