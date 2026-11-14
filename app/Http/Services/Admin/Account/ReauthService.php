<?php

namespace App\Http\Services\Admin\Account;

use App\Http\Services\Admin\AdminAudit;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Step-up authentication: the logged-in person proves again she is the owner (password, plus the TOTP code when she has
 * two-factor) and the token is stamped `reauthenticated_at`. `admin.reauth` then lets the very sensitive actions
 * through for `admin.reauth_minutes`. Failures count per person, across tokens, and lock the endpoint for 15 minutes.
 */
class ReauthService
{
    private const MAX_FAILURES = 5;

    private const LOCK_SECONDS = 900;

    public function __construct(private readonly TwoFactorService $twoFactor, private readonly AdminAudit $audit) {}

    /** Whether the token carries a re-authentication that is still valid. */
    public static function isFresh(mixed $token): bool
    {
        return self::until($token) !== null;
    }

    /** When the current re-authentication of this token ends, or null if there is none (or it already ended). */
    public static function until(mixed $token): ?CarbonInterface
    {
        if (! $token instanceof PersonalAccessToken || ! $token->reauthenticated_at) {
            return null;
        }
        $until = Carbon::parse($token->reauthenticated_at)->addMinutes(max(1, (int) config('admin.reauth_minutes')));

        return $until->isFuture() ? $until : null;
    }

    /**
     * @throws ValidationException wrong password or code (422 on `password` / `code`)
     * @throws ThrottleRequestsException five failures in fifteen minutes
     */
    public function confirm(User $user, PersonalAccessToken $token, ?string $password, ?string $code): CarbonInterface
    {
        $key = "admin-reauth:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            throw new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        $field = null;
        if (! is_string($password) || $password === '' || ! Hash::check($password, $user->password)) {
            $field = 'password';
        } elseif ($user->hasAdminTwoFactor() && (! is_string($code) || $code === '' || ! $this->twoFactor->verifyLoginCode($user, $code))) {
            $field = 'code';
        }

        if ($field) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            // Only which factor failed is recorded, never what was typed.
            $this->audit->record('account.reauth_failed', $user, ['after' => ['factor' => $field === 'code' ? 'totp' : 'password']]);

            throw ValidationException::withMessages([$field => [$field === 'password' ? 'La contraseña no es correcta.' : 'El código no es válido.']]);
        }

        RateLimiter::clear($key);
        $token->forceFill(['reauthenticated_at' => now()])->save();
        $this->audit->record('account.reauth', $user, ['after' => ['method' => $user->hasAdminTwoFactor() ? 'password+totp' : 'password']]);

        return self::until($token);
    }
}
