<?php

namespace App\Http\Services\Auth;

use App\Mail\ConfirmAccountEmail;
use App\Models\User;
use App\Support\Mail\MailLinks;
use App\Support\Mail\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** Verification of the organizer's email: the signed link in the mail, the check of that link and the resend. */
class EmailVerificationService
{
    public const LINK_MINUTES = 60;

    public function url(User $user): string
    {
        return URL::temporarySignedRoute(
            'auth.verify.email',
            now()->addMinutes(self::LINK_MINUTES),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
    }

    public function send(User $user): void
    {
        Outbox::send($user->email, new ConfirmAccountEmail($user, $this->url($user)));
    }

    /**
     * Checks a click on the link and verifies the address. Returns `ok` (also when it was already verified),
     * `expired` (right signature, past its time) or `invalid` (altered, or not this user's address).
     */
    public function verify(Request $request, int $id, string $hash): string
    {
        if (! URL::hasCorrectSignature($request)) {
            return 'invalid';
        }

        $user = User::query()->find($id);
        if (! $user || ! hash_equals(sha1($user->email), $hash)) {
            return 'invalid';
        }

        if ($user->email_verified_at) {
            return 'ok';
        }

        if (! URL::signatureHasNotExpired($request)) {
            return 'expired';
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return 'ok';
    }

    public function redirectUrl(string $status): string
    {
        return MailLinks::app('email-verified?status=' . $status);
    }
}
