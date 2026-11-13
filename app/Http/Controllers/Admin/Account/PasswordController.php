<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Account\AccountSecurity;
use App\Http\Services\Admin\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public const MIN_LENGTH = 12;

    public function __construct(private readonly AdminAudit $audit, private readonly AccountSecurity $security) {}

    /**
     * Changes the password: the current one is required (and locks after five misses), the new one has at least 12
     * characters and is not the old one. Every other session is closed and she gets a notice by mail.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:128'],
            'newPasswordConfirmation' => ['required', 'same:newPassword'],
        ], [
            'currentPassword.required' => 'Escribe tu contraseña actual.',
            'newPassword.required' => 'Escribe la contraseña nueva.',
            'newPassword.min' => 'La contraseña nueva debe tener al menos '.self::MIN_LENGTH.' caracteres.',
            'newPassword.max' => 'La contraseña nueva es demasiado larga.',
            'newPasswordConfirmation.required' => 'Repite la contraseña nueva.',
            'newPasswordConfirmation.same' => 'Las contraseñas no coinciden.',
        ]);

        $this->security->confirmPassword($user, $data['currentPassword']);

        if (Hash::check($data['newPassword'], $user->password)) {
            throw ValidationException::withMessages(['newPassword' => ['La contraseña nueva debe ser distinta de la actual.']]);
        }
        if (Str::lower($data['newPassword']) === Str::lower($user->email)) {
            throw ValidationException::withMessages(['newPassword' => ['La contraseña no puede ser tu correo.']]);
        }

        $user->forceFill(['password' => $data['newPassword'], 'password_changed_at' => now()])->save();
        $user->adminEmailChange()->delete(); // a pending email change was asked with the old password

        $closed = $this->security->revokeTokens($user, $this->security->currentToken($user));

        // Never the passwords: only that it happened and how many sessions it closed.
        $this->audit->record('account.password_changed', $user, ['after' => ['sessionsClosed' => $closed]]);
        $this->security->notify($user, $user, 'password_changed');

        return response()->json(['data' => ['sessionsClosed' => $closed, 'passwordChangedAt' => $user->password_changed_at->toIso8601String()]]);
    }
}
