<?php

namespace App\Http\Services\Admin\Team;

use App\Http\Controllers\Admin\Account\PasswordController;
use App\Http\Services\Admin\Account\AdminLoginService;
use App\Http\Services\Admin\Account\TwoFactorService;
use App\Http\Services\Admin\AdminAudit;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The public side of an invitation: look the token up, and accept it. Accepting creates the account (or, for an
 * existing Quinvite account, only confirms with her CURRENT password and keeps it), gives the staff role, verifies the
 * email, deletes the invitation (one use) and signs her in like the admin login does.
 */
class InvitationAcceptance
{
    /** Wrong current passwords allowed per account in 15 minutes. */
    private const MAX_PASSWORD_FAILURES = 5;

    public function __construct(private readonly AdminAudit $audit, private readonly AdminLoginService $login) {}

    /** The live invitation of that token: 404 `invitation_invalid` or 410 `invitation_expired` otherwise. */
    public function lookup(string $token): AdminInvitation
    {
        $invitation = AdminInvitation::findByToken($token) ?? throw TeamInvitations::invalid();

        if ($invitation->isExpired()) {
            throw TeamInvitations::expired();
        }

        return $invitation;
    }

    public function preview(AdminInvitation $invitation): array
    {
        $account = TeamInvitations::accountFor($invitation->email);

        return [
            'name' => $invitation->name ?: $account?->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'accountExists' => (bool) $account,
            'requiresTwoFactor' => TwoFactorService::requiredForRole($invitation->role),
        ];
    }

    /**
     * @return array{data: array}|array{twoFactorRequired: true, challenge: string} the answer of the endpoint
     *
     * @throws ValidationException
     */
    public function accept(Request $request, AdminInvitation $invitation): array
    {
        $account = TeamInvitations::accountFor($invitation->email);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'password' => $account ? ['nullable'] : ['required', 'string', 'min:' . PasswordController::MIN_LENGTH, 'max:128', 'confirmed'],
            'password_confirmation' => ['nullable', 'string'],
            'current_password' => ['nullable', 'string'],
            'device' => ['nullable', 'string', 'max:255'],
        ], [
            'password.required' => 'Escribe la contraseña.',
            'password.min' => 'La contraseña debe tener al menos ' . PasswordController::MIN_LENGTH . ' caracteres.',
            'password.max' => 'La contraseña es demasiado larga.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $name = trim((string) ($data['name'] ?? '')) ?: $invitation->name ?: $account?->name;
        if (! $name) {
            throw ValidationException::withMessages(['name' => ['name_required']]);
        }

        if ($account) {
            $this->confirmCurrentPassword($account, $data['current_password'] ?? null);
            $password = $data['current_password'];
        } else {
            $password = $data['password'];
            if (Str::lower($password) === Str::lower($invitation->email)) {
                throw ValidationException::withMessages(['password' => ['La contraseña no puede ser tu correo.']]);
            }
        }

        $user = DB::transaction(function () use ($invitation, $account, $name, $password) {
            // Lock the row: two requests with the same link cannot both pass.
            $fresh = AdminInvitation::query()->lockForUpdate()->find($invitation->id);
            if (! $fresh || ! hash_equals($fresh->token_hash, $invitation->token_hash) || $fresh->isExpired()) {
                throw TeamInvitations::invalid();
            }

            $user = $account ? User::query()->lockForUpdate()->find($account->id) : null;
            if ($user?->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
                $fresh->delete(); // she joined some other way: this link has nothing left to do
                throw TeamInvitations::invalid();
            }

            if (! $user) {
                $user = User::query()->create(['name' => $name, 'email' => $fresh->email, 'password' => Hash::make($password)]);
                $user->forceFill(['password_changed_at' => now()]);
            }
            // She is who owns that mailbox: the link proves it.
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'admin_suspended_at' => null])->save();
            $user->assignRole($fresh->role);
            $fresh->delete();

            $this->audit->record('team.invite_accept', $user, [
                'actor' => $user,
                'after' => ['role' => $fresh->role, 'accountExisted' => (bool) $account, 'invitedBy' => $fresh->invited_by],
            ]);

            return $user;
        });

        $result = $this->login->attempt($user->email, $password, $data['device'] ?? 'admin');
        if (is_array($result)) { // she already had two-factor on the admin: the second step of the login follows
            return $result;
        }

        $user->unsetRelation('adminTwoFactor');

        return ['data' => [
            'token' => $result,
            'twoFactorSetupRequired' => TwoFactorService::requiredFor($user) && ! $user->hasAdminTwoFactor(),
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'roles' => $user->getRoleNames()->intersect(AdminPermissions::STAFF_ROLES)->values()],
        ]];
    }

    private function confirmCurrentPassword(User $account, ?string $password): void
    {
        $key = "admin-invitation-password:{$account->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_PASSWORD_FAILURES)) {
            throw new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        if (! is_string($password) || $password === '' || ! Hash::check($password, $account->password)) {
            RateLimiter::hit($key, 900);

            throw ValidationException::withMessages(['current_password' => ['invalid_current_password']]);
        }

        RateLimiter::clear($key);
    }
}
