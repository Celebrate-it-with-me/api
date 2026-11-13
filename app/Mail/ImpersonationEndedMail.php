<?php

namespace App\Mail;

use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Tells a user that the Quinvite team entered their account to help them. es/en by the user's language; it never
 * carries the staff member's name. Plain text on purpose (the brand mail theme arrives with another branch).
 */
class ImpersonationEndedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $lang;

    public function __construct(public ImpersonationSession $session, public User $user)
    {
        $this->lang = str_starts_with((string) $user->preferences?->language, 'es') ? 'es' : 'en';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->lang === 'es'
                ? 'El equipo de Quinvite entró a tu cuenta para ayudarte'
                : 'The Quinvite team accessed your account to help you',
        );
    }

    public function content(): Content
    {
        $timezone = $this->user->preferences?->timezone ?: config('app.timezone');
        $date = $this->session->started_at->copy()->timezone($timezone)->locale($this->lang)
            ->translatedFormat($this->lang === 'es' ? 'j \d\e F \d\e Y, H:i' : 'F j, Y, H:i');

        return new Content(
            text: "emails.impersonation.ended-{$this->lang}",
            with: [
                'name' => $this->user->name,
                'date' => $date,
                'minutes' => app(ImpersonationService::class)->minutes($this->session),
                'reason' => Str::limit(trim((string) $this->session->reason), 140),
                'supportEmail' => config('services.support.email'),
            ],
        );
    }
}
