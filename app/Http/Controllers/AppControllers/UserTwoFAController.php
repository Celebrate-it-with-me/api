<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Services\AppServices\UserTwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Two-factor (TOTP) of the logged-in organizer. The secret is returned once, by `setup`; recovery codes once each time
 * they are created (`enable`, `regenerate`). Nothing else ever returns either. Enabling, disabling and regenerating
 * need the current password; every check of a code is throttled per user.
 */
class UserTwoFAController extends Controller
{
    private const MAX_ATTEMPTS = 6;

    private const DECAY_SECONDS = 900;

    public function __construct(private readonly UserTwoFactorService $twoFactor) {}

    /** Starts (or restarts) enrolment; refused when two-factor is already active. */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($this->twoFactor->isActive($user)) {
            return $this->alreadyActive();
        }

        $setup = $this->twoFactor->startSetup($user);
        $qrImage = QrCode::format('png')->size(200)->generate($setup['otpauthUrl']);

        return response()->json([
            'qr_code' => base64_encode($qrImage),
            'secret' => $setup['secret'],
        ]);
    }

    /** Activates with a valid code and the current password; returns the recovery codes (the only time). */
    public function enable(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:20', 'currentPassword' => 'required|string']);
        $user = $request->user();

        if ($this->twoFactor->isActive($user)) {
            return $this->alreadyActive();
        }
        $this->confirmPassword($request);
        $this->throttle($user->id);
        if (! $this->twoFactor->hasSecret($user)) {
            throw ValidationException::withMessages(['code' => ['Set up two-factor authentication first.']]);
        }

        $codes = $this->twoFactor->confirm($user, $request->input('code'));
        if ($codes === null) {
            $this->hit($user->id);

            return response()->json(['message' => 'Invalid verification code'], 422);
        }
        RateLimiter::clear($this->key($user->id));

        return response()->json(['message' => 'Two-factor authentication enabled', 'recovery_codes' => $codes]);
    }

    /** Turns two-factor off (current password required). */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['currentPassword' => 'required|string']);
        $this->confirmPassword($request);

        $this->twoFactor->disable($request->user());

        return response()->json(['message' => 'Two-factor authentication disabled']);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $active = $this->twoFactor->isActive($user);

        return response()->json([
            'enabled' => $active,
            'verified' => $active,
            'setupDone' => $this->twoFactor->hasSecret($user),
            'recoveryCodesRemaining' => $active ? $this->twoFactor->recoveryCodesRemaining($user) : 0,
        ]);
    }

    /** The codes are stored as hashes and cannot be shown again: this only says how many are left. Use `regenerate` for new ones. */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->twoFactor->isActive($user)) {
            return response()->json(['message' => 'No recovery codes available'], 404);
        }

        return response()->json(['remaining' => $this->twoFactor->recoveryCodesRemaining($user)]);
    }

    /** New recovery codes (current password required); the previous ones stop working. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['currentPassword' => 'required|string']);
        $user = $request->user();
        if (! $this->twoFactor->isActive($user)) {
            return response()->json(['message' => 'Two-factor authentication is not active'], 409);
        }
        $this->confirmPassword($request);

        return response()->json(['codes' => $this->twoFactor->regenerateRecoveryCodes($user)]);
    }

    /** Checks a TOTP code or a recovery code (single use) of an active enrolment. */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:20']);
        $user = $request->user();
        if (! $this->twoFactor->isActive($user)) {
            return response()->json(['message' => 'Two-factor authentication is not active'], 409);
        }
        $this->throttle($user->id);

        $code = $request->input('code');
        $valid = $this->twoFactor->verifyCode($user, $code) || $this->twoFactor->useRecoveryCode($user, $code);
        if (! $valid) {
            $this->hit($user->id);

            return response()->json(['message' => 'Invalid verification code'], 422);
        }
        RateLimiter::clear($this->key($user->id));

        return response()->json(['valid' => true]);
    }

    private function confirmPassword(Request $request): void
    {
        $user = $request->user();
        $this->throttle($user->id);
        if (! Hash::check($request->input('currentPassword'), $user->password)) {
            $this->hit($user->id);

            throw ValidationException::withMessages(['currentPassword' => ['The current password is not correct.']]);
        }
    }

    private function key(int $userId): string
    {
        return "app-2fa:{$userId}";
    }

    private function throttle(int $userId): void
    {
        if (RateLimiter::tooManyAttempts($this->key($userId), self::MAX_ATTEMPTS)) {
            abort(429, 'Too many attempts. Try again later.');
        }
    }

    private function hit(int $userId): void
    {
        RateLimiter::hit($this->key($userId), self::DECAY_SECONDS);
    }

    private function alreadyActive(): JsonResponse
    {
        return response()->json(['message' => 'Two-factor authentication is already active'], 409);
    }
}
