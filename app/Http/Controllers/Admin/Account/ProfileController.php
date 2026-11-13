<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Account\AccountSecurity;
use App\Http\Services\Admin\Account\TwoFactorService;
use App\Http\Services\Admin\AdminAudit;
use App\Mail\AdminEmailConfirmMail;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\AdminPermissions;
use App\Support\Mail\MailLinks;
use App\Support\Mail\MailLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Mi cuenta" of the staff, profile part. Every endpoint works on the logged-in person only: there is no user id in any
 * route, so nobody can reach another account through here.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AdminAudit $audit, private readonly AccountSecurity $security) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    /** Name and the language of the mails she receives. */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'language' => ['sometimes', 'required', Rule::in(MailLocale::SUPPORTED)],
        ], [
            'name.required' => 'Escribe tu nombre.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'language.in' => 'Elige español o inglés.',
        ]);

        $before = ['name' => $user->name, 'language' => MailLocale::forUser($user)];

        DB::transaction(function () use ($user, $data) {
            if (isset($data['name'])) {
                $user->forceFill(['name' => trim($data['name'])])->save();
            }
            if (isset($data['language'])) {
                UserPreference::updateOrCreate(['user_id' => $user->id], ['language' => $data['language']]);
            }
        });

        $user->unsetRelation('preferences');
        $this->audit->record('account.profile_updated', $user, [
            'before' => $before,
            'after' => ['name' => $user->name, 'language' => MailLocale::forUser($user)],
        ]);

        return response()->json(['data' => $this->present($user->fresh())]);
    }

    /** Asks to change the sign-in email: current password, then a link to the NEW address; nothing changes until it is used. */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
        ], ['email.required' => 'Escribe el correo nuevo.', 'email.email' => 'Escribe un correo válido.']);

        $this->security->confirmPassword($user, $data['password'], 'password');

        $email = Str::lower(trim($data['email']));
        if ($email === Str::lower($user->email)) {
            throw ValidationException::withMessages(['email' => ['Ese ya es tu correo.']]);
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => ['Ese correo ya está en uso.']]);
        }

        $token = Str::random(64);
        $minutes = (int) config('admin.email_change_minutes', 60);
        $user->adminEmailChange()->updateOrCreate([], [
            'new_email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        $this->security->mailSafely(fn () => Mail::to($email)->send(
            new AdminEmailConfirmMail($user, MailLinks::admin('dashboard/account/confirm-email?token=' . $token), $minutes)
        ), $user);
        $this->audit->record('account.email_change_requested', $user, ['after' => ['newEmail' => $email]]);

        return response()->json(['data' => $this->present($user)], 202);
    }

    /** Opened from the link in the mail, by the same logged-in person. */
    public function confirmEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);

        $pending = $user->adminEmailChange()->first();
        $valid = $pending && $pending->expires_at->isFuture() && hash_equals($pending->token_hash, hash('sha256', $data['token']));
        if (! $valid) {
            throw ValidationException::withMessages(['token' => ['El enlace no es válido o venció. Pide el cambio de nuevo.']]);
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [Str::lower($pending->new_email)])->whereKeyNot($user->id)->exists()) {
            $pending->delete();
            throw ValidationException::withMessages(['token' => ['Ese correo ya está en uso.']]);
        }

        $old = $user->email;
        DB::transaction(function () use ($user, $pending) {
            $user->forceFill(['email' => $pending->new_email, 'email_verified_at' => now()])->save();
            $pending->delete();
        });

        $this->audit->record('account.email_changed', $user, ['before' => ['email' => $old], 'after' => ['email' => $user->email]]);
        // The warning goes to the address she had, in case it was not her.
        $this->security->notify($old, $user, 'email_changed', ['newEmail' => $user->email]);

        return response()->json(['data' => $this->present($user->fresh())]);
    }

    public function cancelEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->adminEmailChange()->delete()) {
            $this->audit->record('account.email_change_cancelled', $user);
        }

        return response()->json(['data' => $this->present($user)]);
    }

    private function present(User $user): array
    {
        $user->load('preferences', 'adminTwoFactor', 'adminEmailChange');
        $pending = $user->adminEmailChange;
        $twoFactor = $user->adminTwoFactor;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->intersect(AdminPermissions::STAFF_ROLES)->values(),
            'language' => MailLocale::forUser($user),
            'createdAt' => $user->created_at?->toIso8601String(),
            'passwordChangedAt' => $user->password_changed_at?->toIso8601String(),
            'pendingEmail' => $pending && $pending->expires_at->isFuture() ? ['email' => $pending->new_email, 'expiresAt' => $pending->expires_at->toIso8601String()] : null,
            'twoFactor' => [
                'enabled' => (bool) $twoFactor?->isActive(),
                'confirmedAt' => $twoFactor?->confirmed_at?->toIso8601String(),
                'recoveryCodesRemaining' => $twoFactor?->isActive() ? $twoFactor->recoveryCodesRemaining() : 0,
                'required' => TwoFactorService::requiredFor($user),
            ],
        ];
    }
}
