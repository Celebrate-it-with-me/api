<?php

namespace App\Http\Services\Admin\Account;

use App\Http\Services\Admin\System\TwoFactorEnforcement;
use App\Models\AdminTwoFactor;
use App\Models\User;
use App\Support\AdminPermissions;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * TOTP for the staff (RFC 6238, through pragmarx/google2fa, already a dependency). The secret is encrypted by the model;
 * recovery codes are shown once and kept as keyed hashes (HMAC-SHA256 with the app key), compared with hash_equals over
 * the whole list so the time does not reveal which one matched.
 */
class TwoFactorService
{
    public const RECOVERY_CODES = 8;

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Whether the server forces this person to have two-factor on. Two independent rules, ORed:
     *  - the switch in System > Security (`system_flags`, see TwoFactorEnforcement): every person with a staff role;
     *  - the environment variable (config `admin.require_two_factor_superadmin`): superadmins only. It is kept as a
     *    fallback and, being an OR, it cannot be turned off from the panel.
     */
    public static function requiredFor(User $user): bool
    {
        if ((bool) config('admin.require_two_factor_superadmin') && $user->hasRole(AdminPermissions::SUPERADMIN)) {
            return true;
        }

        return $user->hasAnyRole(AdminPermissions::STAFF_ROLES) && app(TwoFactorEnforcement::class)->enabled();
    }

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Starts (or restarts) enrolment: a new pending secret. Returns what the authenticator app needs.
     *
     * @return array{secret: string, otpauthUrl: string}
     */
    public function startSetup(User $user): array
    {
        if ($user->hasAdminTwoFactor()) {
            throw new RuntimeException('Two-factor authentication is already active.');
        }

        $secret = $this->google2fa->generateSecretKey(32);
        AdminTwoFactor::updateOrCreate(
            ['user_id' => $user->id],
            ['secret' => $secret, 'confirmed_at' => null, 'recovery_codes' => null, 'last_used_step' => null],
        );

        return [
            'secret' => $secret,
            'otpauthUrl' => $this->google2fa->getQRCodeUrl('Quinvite Admin', $user->email, $secret),
        ];
    }

    /**
     * Proves the app is set up: with a valid code the enrolment becomes active and the recovery codes are created.
     *
     * @return list<string>|null the plain recovery codes (shown once), or null when the code is wrong
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        $record = $user->adminTwoFactor;
        if (! $record || $record->isActive() || ! $this->acceptCode($record, $code)) {
            return null;
        }

        $record->confirmed_at = now();
        $plain = $this->newRecoveryCodes($record);
        $record->save();

        return $plain;
    }

    /** True for a valid, not-yet-used TOTP code of an ACTIVE enrolment; the code cannot be replayed. */
    public function verifyLoginCode(User $user, string $code): bool
    {
        $record = $user->adminTwoFactor;

        return $record && $record->isActive() && $this->acceptCode($record, $code) && $record->save();
    }

    /** Uses up one recovery code. Wrong or already used: false. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $record = $user->adminTwoFactor;
        if (! $record || ! $record->isActive()) {
            return false;
        }

        $hash = $this->hash($code);
        $codes = $record->recovery_codes ?? [];
        $matched = null;
        foreach ($codes as $i => $entry) {
            // No early exit: every entry is compared.
            if (hash_equals((string) $entry['hash'], $hash) && empty($entry['used_at'])) {
                $matched = $i;
            }
        }
        if ($matched === null) {
            return false;
        }

        $codes[$matched]['used_at'] = now()->toIso8601String();
        $record->recovery_codes = $codes;
        $record->save();

        return true;
    }

    /** @return list<string> the new plain codes; the old ones stop working */
    public function regenerateRecoveryCodes(User $user): array
    {
        $record = $user->adminTwoFactor;
        if (! $record || ! $record->isActive()) {
            throw new RuntimeException('Two-factor authentication is not active.');
        }
        $plain = $this->newRecoveryCodes($record);
        $record->save();

        return $plain;
    }

    public function disable(User $user): void
    {
        $user->adminTwoFactor()->delete();
    }

    /** Checks the code against the secret and, if it is newer than the last accepted one, remembers its time step. */
    private function acceptCode(AdminTwoFactor $record, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // 0, not null: with null the library answers `true` instead of the time step and replay protection is lost.
        $step = $this->google2fa->verifyKeyNewer($record->secret, $code, (int) $record->last_used_step, 1);
        if ($step === false) {
            return false;
        }
        $record->last_used_step = (int) $step;

        return true;
    }

    /** @return list<string> */
    private function newRecoveryCodes(AdminTwoFactor $record): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        $record->recovery_codes = array_map(fn (string $c) => ['hash' => $this->hash($c), 'used_at' => null], $plain);

        return $plain;
    }

    /** Case, dashes and spaces do not matter when typing a code. */
    private function hash(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
