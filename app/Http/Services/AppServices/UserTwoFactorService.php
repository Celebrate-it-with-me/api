<?php

namespace App\Http\Services\AppServices;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * TOTP for the organizers (RFC 6238 through pragmarx/google2fa). The secret lives encrypted on the user (cast
 * `encrypted`, APP_KEY). Recovery codes are shown once and kept as keyed hashes (HMAC-SHA256 with the app key) in
 * `two_factor_recovery_codes` ([{hash, used_at}]), compared with hash_equals over the whole list. A TOTP time step
 * is accepted only once (`two_factor_last_used_step`), so a sniffed code cannot be replayed.
 */
class UserTwoFactorService
{
    public const RECOVERY_CODES = 8;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly Google2FA $google2fa) {}

    public function isActive(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $this->hasSecret($user);
    }

    public function hasSecret(User $user): bool
    {
        return ! empty($user->getRawOriginal('two_factor_secret'));
    }

    /**
     * New pending secret (replaces a previous pending one). Not active until `confirm` gets a valid code.
     *
     * @return array{secret: string, otpauthUrl: string}
     */
    public function startSetup(User $user): array
    {
        if ($this->isActive($user)) {
            throw new RuntimeException('Two-factor authentication is already active.');
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauthUrl' => $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret),
        ];
    }

    /** @return list<string>|null the plain recovery codes (shown once), or null when the code is wrong or there is no pending setup */
    public function confirm(User $user, string $code): ?array
    {
        if ($this->isActive($user) || ! $this->hasSecret($user) || ! $this->acceptCode($user, $code)) {
            return null;
        }

        $user->two_factor_confirmed_at = now();
        $plain = $this->newRecoveryCodes($user);
        $user->save();

        return $plain;
    }

    /** True for a valid, never-used TOTP code of an ACTIVE enrolment. */
    public function verifyCode(User $user, string $code): bool
    {
        return $this->isActive($user) && $this->acceptCode($user, $code) && $user->save();
    }

    /** Uses up one recovery code. Wrong or already used: false. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        $hash = $this->hash($code);
        $codes = $user->two_factor_recovery_codes ?? [];
        $matched = null;
        foreach ($codes as $i => $entry) {
            // No early exit: every entry is compared.
            if (hash_equals((string) ($entry['hash'] ?? ''), $hash) && empty($entry['used_at'])) {
                $matched = $i;
            }
        }
        if ($matched === null) {
            return false;
        }

        $codes[$matched]['used_at'] = now()->toIso8601String();
        $user->two_factor_recovery_codes = $codes;
        $user->save();

        return true;
    }

    /** @return list<string> the new plain codes; the old ones stop working */
    public function regenerateRecoveryCodes(User $user): array
    {
        if (! $this->isActive($user)) {
            throw new RuntimeException('Two-factor authentication is not active.');
        }
        $plain = $this->newRecoveryCodes($user);
        $user->save();

        return $plain;
    }

    public function recoveryCodesRemaining(User $user): int
    {
        return collect($user->two_factor_recovery_codes ?? [])->whereNull('used_at')->count();
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
    }

    private function acceptCode(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // 0, not null: with null the library answers `true` instead of the time step and replay protection is lost.
        $step = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, (int) $user->two_factor_last_used_step, 1);
        if ($step === false) {
            return false;
        }
        $user->two_factor_last_used_step = (int) $step;

        return true;
    }

    /** @return list<string> */
    private function newRecoveryCodes(User $user): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        $user->two_factor_recovery_codes = array_map(fn (string $c) => ['hash' => $this->hash($c), 'used_at' => null], $plain);

        return $plain;
    }

    /** Case, dashes and spaces do not matter when typing a code. */
    public static function hash(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
