<?php

namespace App\Http\Services\Admin\Account;

use App\Http\Services\Admin\AdminAudit;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\AdminPermissions;
use App\Support\ClientInfo;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in to the admin panel in one or two steps. Step one checks the password; if the person has two-factor on, it
 * answers with a short-lived `challenge` instead of a token, and step two trades the challenge plus a code (or a
 * recovery code) for the token. The challenge lives in the cache (5 minutes, 5 wrong codes), never in the client.
 */
class AdminLoginService
{
    private const CHALLENGE_MINUTES = 5;

    private const CHALLENGE_MAX_ATTEMPTS = 5;

    /** Wrong second-step codes allowed per person in 15 minutes, whatever the challenge. */
    private const USER_MAX_FAILURES = 10;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AccountSecurity $security,
        private readonly AdminAudit $audit,
        private readonly Request $request,
    ) {}

    /**
     * @return string|array{twoFactorRequired: true, challenge: string} the plain token, or the challenge to answer
     *
     * @throws ValidationException
     */
    public function attempt(string $email, string $password, string $device): string|array
    {
        $user = User::query()->where('email', $email)->first();
        $isStaff = $user?->hasAnyRole(AdminPermissions::STAFF_ROLES);

        // A token for the admin panel is only for administrators; anyone else gets the same answer as a wrong password.
        if (! $user || ! Hash::check($password, $user->password) || ! $isStaff) {
            // A failed attempt on a real staff account leaves a trace in her own history (throttled by IP on the route).
            if ($user && $isStaff) {
                $this->audit->record('auth.login_failed', $user, ['actor' => $user, 'after' => ['step' => 'password']]);
            }

            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        if ($user->hasAdminTwoFactor()) {
            return ['twoFactorRequired' => true, 'challenge' => $this->openChallenge($user)];
        }

        return $this->issueToken($user, $device, 'password');
    }

    /**
     * @param  string|null  $code  six-digit TOTP code
     * @param  string|null  $recoveryCode  one of the eight recovery codes
     *
     * @throws ValidationException
     */
    public function complete(string $challenge, ?string $code, ?string $recoveryCode, string $device): string
    {
        $key = $this->challengeKey($challenge);
        $data = Cache::get($key);
        $user = $data ? User::find($data['user']) : null;

        if (! $user || ! $user->hasAnyRole(AdminPermissions::STAFF_ROLES) || ! $user->hasAdminTwoFactor()) {
            Cache::forget($key);

            throw ValidationException::withMessages(['challenge' => ['The sign-in attempt expired. Start again.']]);
        }

        $limiter = "admin-2fa-failures:{$user->id}";
        if (RateLimiter::tooManyAttempts($limiter, self::USER_MAX_FAILURES)) {
            throw new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => RateLimiter::availableIn($limiter)]);
        }

        $usingRecovery = $recoveryCode !== null && $recoveryCode !== '';
        $ok = $usingRecovery
            ? $this->twoFactor->useRecoveryCode($user, $recoveryCode)
            : ($code !== null && $this->twoFactor->verifyLoginCode($user, $code));

        if (! $ok) {
            RateLimiter::hit($limiter, 900);
            $data['attempts']++;
            // The secret of the attempt is never logged: only that it failed and which kind of code was tried.
            $this->audit->record('account.two_factor_failed', $user, ['actor' => $user, 'after' => ['method' => $usingRecovery ? 'recovery_code' : 'totp']]);

            if ($data['attempts'] >= self::CHALLENGE_MAX_ATTEMPTS) {
                Cache::forget($key);
                throw ValidationException::withMessages(['challenge' => ['Too many wrong codes. Start again.']]);
            }
            Cache::put($key, $data, now()->addMinutes(self::CHALLENGE_MINUTES));

            throw ValidationException::withMessages([$usingRecovery ? 'recoveryCode' : 'code' => ['The code is not valid.']]);
        }

        RateLimiter::clear($limiter);
        Cache::forget($key); // a challenge works once

        if ($usingRecovery) {
            $this->audit->record('account.recovery_code_used', $user, ['actor' => $user, 'after' => ['remaining' => $user->adminTwoFactor()->first()->recoveryCodesRemaining()]]);
        }

        return $this->issueToken($user, $device, $usingRecovery ? 'recovery_code' : 'two_factor');
    }

    private function issueToken(User $user, string $device, string $method): string
    {
        $label = ClientInfo::device($this->request->userAgent());
        $isNewDevice = $this->isNewDevice($user, $label);

        $this->audit->record('auth.login', $user, ['actor' => $user, 'after' => ['method' => $method, 'device' => $label]]);

        $token = $user->createToken($device);
        $token->accessToken->forceFill([
            'client' => 'admin',
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255) ?: null,
        ])->save();

        if ($isNewDevice) {
            $timezone = $user->preferences?->timezone ?: config('app.timezone');
            $this->security->notify($user, $user, 'new_device', [
                'device' => $label,
                'when' => now()->timezone($timezone)->format('d/m/Y H:i'),
                'ip' => ClientInfo::maskIp($this->request->ip()) ?? '-',
            ]);
        }

        return $token->plainTextToken;
    }

    /** A device is new when she has signed in before, but never from this browser and system. The first sign-in is not "new". */
    private function isNewDevice(User $user, string $label): bool
    {
        $previous = AdminAuditLog::query()->where('actor_id', $user->id)->where('action', 'auth.login')->latest('id')->limit(200)->get(['after']);

        return $previous->isNotEmpty() && ! $previous->contains(fn ($row) => ($row->after['device'] ?? null) === $label);
    }

    private function openChallenge(User $user): string
    {
        $challenge = Str::random(48);
        Cache::put($this->challengeKey($challenge), ['user' => $user->id, 'attempts' => 0], now()->addMinutes(self::CHALLENGE_MINUTES));

        return $challenge;
    }

    private function challengeKey(string $challenge): string
    {
        return 'admin-2fa-challenge:' . hash('sha256', $challenge);
    }
}
