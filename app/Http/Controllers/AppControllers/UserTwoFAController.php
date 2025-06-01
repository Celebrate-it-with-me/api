<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException;
use PragmaRX\Google2FA\Exceptions\InvalidCharactersException;
use PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException;
use PragmaRX\Google2FA\Google2FA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class UserTwoFAController extends Controller
{
    protected Google2FA $google2FA;

    public function __construct(Google2FA $google2FA)
    {
        $this->google2FA = $google2FA;
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws SecretKeyTooShortException
     * @throws InvalidCharactersException
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->two_factor_secret) {
            $user->two_factor_secret = $this->google2FA->generateSecretKey();
            $user->save();
        }

        $qrImage = QrCode::format('png')
            ->size(200)
            ->generate($this->google2FA->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $user->two_factor_secret,
            ));

        $recoveryCodes = collect(range(1, 8))->map(fn () => Str::random(10))->toArray();
        $user->two_factor_recovery_codes = json_encode($recoveryCodes);
        $user->save();

        return response()->json([
            'qr_code' => base64_encode($qrImage),
            'secret' => $user->two_factor_secret,
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws SecretKeyTooShortException
     * @throws InvalidCharactersException
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();

        $valid = $this->google2FA->verifyKey($user->two_factor_secret, $request->code);

        if (! $valid) {
            return response()->json(['message' => 'Invalid verification code'], 422);
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        return response()->json(['message' => 'Two-factor authentication enabled']);
    }

    /**
     * Disables two-factor authentication for the authenticated user.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();

        return response()->json(['message' => 'Two-factor authentication disabled']);
    }

    /**
     * Retrieve the two-factor authentication status for the authenticated user.
     */
    public function status(): JsonResponse
    {
        $user = auth()->user();
        $doneVerified = (bool) $user->two_factor_confirmed_at;

        $resultResponse = [
            'enabled' => $doneVerified,
            'verified' => $doneVerified,
            'setupDone' => (bool) $user->two_factor_secret,
        ];

        return response()->json($resultResponse);
    }

    public function recoveryCodes()
    {
        $user = auth()->user();

        if (! $user->two_factor_recovery_codes) {
            return response()->json(['message' => 'No recovery codes available'], 404);
        }

        $recoveryCodes = json_decode($user->two_factor_recovery_codes, true);

        return response()->json([
            'codes' => $recoveryCodes,
        ]);
    }
}
