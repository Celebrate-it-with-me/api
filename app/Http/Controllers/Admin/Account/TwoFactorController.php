<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Account\AccountSecurity;
use App\Http\Services\Admin\Account\TwoFactorService;
use App\Http\Services\Admin\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor (TOTP) of the logged-in staff member. The secret is returned exactly once, by `setup`; recovery codes
 * exactly once each time they are created. Nothing else ever returns either.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AccountSecurity $security,
        private readonly AdminAudit $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->status($request)]);
    }

    /** Starts enrolment (password required). Not active until `confirm` gets a valid code. */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']], ['password.required' => 'Escribe tu contraseña.']);
        $this->security->confirmPassword($user, $request->input('password'), 'password');

        if ($user->hasAdminTwoFactor()) {
            return $this->alreadyEnabled();
        }

        $setup = $this->twoFactor->startSetup($user);
        $this->audit->record('account.two_factor_setup_started', $user);

        return response()->json(['data' => $setup]);
    }

    /** Proves the authenticator works; activates two-factor and returns the recovery codes (the only time). */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string', 'max:20']], ['code.required' => 'Escribe el código de 6 dígitos.']);

        if ($user->hasAdminTwoFactor()) {
            return $this->alreadyEnabled();
        }
        if (! $user->adminTwoFactor) {
            throw ValidationException::withMessages(['code' => ['Primero genera el código QR.']]);
        }

        $limiter = "admin-2fa-confirm:{$user->id}";
        if (RateLimiter::tooManyAttempts($limiter, 8)) {
            abort(429, 'Too many attempts.');
        }

        $codes = $this->twoFactor->confirmSetup($user, $request->input('code'));
        if ($codes === null) {
            RateLimiter::hit($limiter, 900);

            throw ValidationException::withMessages(['code' => ['El código no es correcto. Revisa la hora de tu teléfono e inténtalo de nuevo.']]);
        }
        RateLimiter::clear($limiter);

        $this->audit->record('account.two_factor_enabled', $user);
        $this->security->notify($user, $user, 'two_factor_enabled');

        return response()->json(['data' => ['recoveryCodes' => $codes] + $this->status($request)]);
    }

    /** New recovery codes (password required); the previous ones stop working. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']], ['password.required' => 'Escribe tu contraseña.']);
        $this->security->confirmPassword($user, $request->input('password'), 'password');

        if (! $user->hasAdminTwoFactor()) {
            return $this->notEnabled();
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        $this->audit->record('account.recovery_codes_regenerated', $user);
        $this->security->notify($user, $user, 'recovery_codes_regenerated');

        return response()->json(['data' => ['recoveryCodes' => $codes] + $this->status($request)]);
    }

    /** Turns two-factor off (password required) and closes her other sessions. */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']], ['password.required' => 'Escribe tu contraseña.']);
        $this->security->confirmPassword($user, $request->input('password'), 'password');

        // Also clears a half-finished enrolment (QR shown, never confirmed).
        $wasActive = $user->hasAdminTwoFactor();
        $this->twoFactor->disable($user);
        $closed = $wasActive ? $this->security->revokeTokens($user, $this->security->currentToken($user)) : 0;

        if ($wasActive) {
            $this->audit->record('account.two_factor_disabled', $user, ['after' => ['sessionsClosed' => $closed]]);
            $this->security->notify($user, $user, 'two_factor_disabled');
        }
        $user->unsetRelation('adminTwoFactor');

        return response()->json(['data' => $this->status($request)]);
    }

    private function status(Request $request): array
    {
        $user = $request->user()->load('adminTwoFactor');
        $record = $user->adminTwoFactor;

        return [
            'enabled' => (bool) $record?->isActive(),
            'confirmedAt' => $record?->confirmed_at?->toIso8601String(),
            'recoveryCodesRemaining' => $record?->isActive() ? $record->recoveryCodesRemaining() : 0,
            'required' => TwoFactorService::requiredFor($user),
        ];
    }

    private function alreadyEnabled(): JsonResponse
    {
        return response()->json(['message' => 'Two-factor authentication is already active.', 'code' => 'two_factor_already_enabled'], 409);
    }

    private function notEnabled(): JsonResponse
    {
        return response()->json(['message' => 'Two-factor authentication is not active.', 'code' => 'two_factor_not_enabled'], 409);
    }
}
