<?php

namespace App\Mail;

use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Support\Mail\MailLinks;
use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a user that the Quinvite team entered their account to help them. es/en by the user's language; it never
 * carries the staff member's name.
 */
class ImpersonationEndedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $lang;

    public function __construct(public ImpersonationSession $session, public User $user)
    {
        $this->lang = MailLocale::forUser($user);
        $this->locale($this->lang);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.impersonation_ended.subject'));
    }

    public function content(): Content
    {
        $timezone = $this->user->preferences?->timezone ?: config('app.timezone');
        $date = $this->session->started_at->copy()->timezone($timezone)->locale($this->lang)
            ->translatedFormat($this->lang === 'es' ? 'j \d\e F \d\e Y, H:i' : 'F j, Y, H:i');
        $minutes = app(ImpersonationService::class)->minutes($this->session);

        return new Content(
            markdown: 'mail.impersonation-ended',
            with: [
                'name' => $this->user->name,
                'date' => $date,
                'minutes' => $minutes,
                'unit' => __($minutes === 1 ? 'mail.impersonation_ended.unit_one' : 'mail.impersonation_ended.unit_other'),
                // The internal reason is free text written by staff: it never goes to the user. If the session came from
                // one of her help requests we say so; otherwise a generic line.
                'why' => __($this->session->support_request_id ? 'mail.impersonation_ended.why_request' : 'mail.impersonation_ended.why_account'),
                'supportEmail' => config('services.support.email'),
                'supportUrl' => MailLinks::support(),
            ],
        );
    }
}
