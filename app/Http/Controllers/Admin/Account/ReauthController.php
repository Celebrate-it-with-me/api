<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Account\AccountSecurity;
use App\Http\Services\Admin\Account\ReauthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Step-up re-authentication of the logged-in person (see ReauthService). Never returns a secret. */
class ReauthController extends Controller
{
    public function __construct(private readonly ReauthService $reauth, private readonly AccountSecurity $security) {}

    /** Is a re-authentication currently valid on this token? */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->status($request->user()->currentAccessToken(), $request->user()->hasAdminTwoFactor())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
        ], ['password.required' => 'Escribe tu contraseña.']);

        $user = $request->user();
        $token = $this->security->currentToken($user);
        if (! $token) {
            return response()->json(['message' => 'Sign in again to confirm your identity.', 'code' => 'token_required'], 409);
        }

        // With two-factor on, the code is mandatory; without it the 422 does not reveal whether the password was right.
        if ($user->hasAdminTwoFactor() && empty($data['code'])) {
            return response()->json(['message' => 'The code is required.', 'errors' => ['code' => ['Escribe el código de tu aplicación de autenticación.']]], 422);
        }

        $this->reauth->confirm($user, $token, $data['password'], $data['code'] ?? null);

        return response()->json(['data' => $this->status($token->refresh(), $user->hasAdminTwoFactor())]);
    }

    private function status(mixed $token, bool $needsCode): array
    {
        $until = ReauthService::until($token);

        return [
            'reauthenticated' => $until !== null,
            'until' => $until?->toIso8601String(),
            'minutes' => (int) config('admin.reauth_minutes'),
            'needsCode' => $needsCode,
        ];
    }
}
