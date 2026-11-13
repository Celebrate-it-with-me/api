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

/**
 * Tells a staff member that something about her sign-in changed (password, email, two-factor, a new device). One class
 * and one view for all of them: `$type` picks the copy in lang/{es,en}/mail.php under `admin_security.types`.
 * It never carries a secret, a code or a link that grants access; the button only opens "Mi cuenta".
 */
class AdminSecurityNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public const TYPES = ['password_changed', 'email_changed', 'two_factor_enabled', 'two_factor_disabled', 'recovery_codes_regenerated', 'new_device'];

    public string $lang;

    /** @param  array{newEmail?: string, device?: string, when?: string, ip?: string}  $details */
    public function __construct(public User $user, public string $type, public array $details = [])
    {
        $this->lang = MailLocale::forUser($user);
        $this->locale($this->lang);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("mail.admin_security.types.{$this->type}.subject"));
    }

    public function content(): Content
    {
        $key = "mail.admin_security.types.{$this->type}";
        $replace = [
            'email' => $this->details['newEmail'] ?? '',
            'device' => $this->details['device'] ?? '',
            'when' => $this->details['when'] ?? '',
            'ip' => $this->details['ip'] ?? '',
        ];

        return new Content(
            markdown: 'mail.admin-security-notice',
            with: [
                'name' => $this->user->name,
                'preheader' => __("{$key}.preheader"),
                'intro' => __("{$key}.intro", $replace),
                'accountUrl' => MailLinks::admin('account'),
                'supportEmail' => config('services.support.email'),
            ],
        );
    }
}
