<?php

namespace App\Http\Services\Admin\Account;

use App\Mail\AdminSecurityNoticeMail;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** What every sensitive action of Mi cuenta shares: re-asking the password (with a lockout), closing sessions, notices. */
class AccountSecurity
{
    /** Wrong current passwords allowed in 15 minutes, across all the account endpoints. */
    private const MAX_PASSWORD_FAILURES = 5;

    private const LOCK_SECONDS = 900;

    /**
     * Checks the current password of the logged-in person. A wrong one is a 422 on `$field`; after five wrong ones in
     * fifteen minutes everything that asks for the password answers 429 until the window passes.
     *
     * @throws ValidationException
     * @throws ThrottleRequestsException
     */
    public function confirmPassword(User $user, ?string $password, string $field = 'currentPassword'): void
    {
        $key = "admin-account-password:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_PASSWORD_FAILURES)) {
            throw new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        if (! is_string($password) || $password === '' || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::LOCK_SECONDS);

            throw ValidationException::withMessages([$field => ['La contraseña actual no es correcta.']]);
        }

        RateLimiter::clear($key);
    }

    /** Deletes every token of the user except the one in use (null: all of them). Returns how many were closed. */
    public function revokeTokens(User $user, ?PersonalAccessToken $except = null): int
    {
        return $user->tokens()
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->delete();
    }

    /** The token of this request, or null (cookie/transient auth in tests). */
    public function currentToken(User $user): ?PersonalAccessToken
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /** Runs a mail send; a mail server that is down never blocks (or undoes) the account change itself. */
    public function mailSafely(\Closure $send, User $user): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Account mail could not be sent', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /** Security notice by mail. A mail server that is down must never block (or undo) the security change itself. */
    public function notify(User|string $to, User $user, string $type, array $details = []): void
    {
        $this->mailSafely(
            fn () => Mail::to($to instanceof User ? $to->email : $to)->send(new AdminSecurityNoticeMail($user, $type, $details)),
            $user,
        );
    }
}
