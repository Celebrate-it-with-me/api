<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Account\AccountSecurity;
use App\Http\Services\Admin\Account\TwoFactorService;
use App\Http\Services\Admin\AdminAudit;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The people who can open the admin panel. Only a superadmin gets here (`admin.team.manage`). */
class TeamController extends Controller
{
    public function __construct(private readonly AdminAudit $audit, private readonly AccountSecurity $security, private readonly TwoFactorService $twoFactor) {}

    public function index(): JsonResponse
    {
        $staff = User::query()->role(AdminPermissions::STAFF_ROLES)->with(['roles', 'adminTwoFactor'])->orderBy('name')->get();

        return response()->json(['data' => $staff->map(fn (User $u) => $this->present($u))->values()]);
    }

    /** Give an existing account a staff role, or create the account (with a temporary password, shown once). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(AdminPermissions::STAFF_ROLES)],
            'name' => ['nullable', 'string', 'max:255'],
            'reason' => AdminAudit::reasonRule(),
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        $temporary = null;

        if ($user?->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            throw ValidationException::withMessages(['email' => ['already_staff']]);
        }

        if (! $user) {
            if (empty($data['name'])) {
                throw ValidationException::withMessages(['name' => ['name_required']]);
            }
            $temporary = Str::password(16, symbols: false);
            $user = User::query()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($temporary), 'email_verified_at' => now()]);
        }

        $user->assignRole($data['role']);
        $this->audit->record($temporary ? 'team.create' : 'team.add', $user, ['reason' => $data['reason'], 'after' => ['role' => $data['role']]]);

        return response()->json(['data' => $this->present($user->refresh()), 'temporaryPassword' => $temporary], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(AdminPermissions::STAFF_ROLES)], 'reason' => AdminAudit::reasonRule()]);
        $this->guard($request, $user);

        $current = $this->roleOf($user);
        if ($current === $data['role']) {
            return response()->json(['data' => $this->present($user)]);
        }
        if ($current === AdminPermissions::SUPERADMIN) {
            $this->assertNotLastSuperadmin();
        }

        DB::transaction(function () use ($user, $data) {
            $user->removeRole(AdminPermissions::SUPERADMIN);
            $user->removeRole(AdminPermissions::SUPPORT);
            $user->assignRole($data['role']);
        });

        $this->audit->record('team.role_changed', $user, ['reason' => $data['reason'], 'before' => ['role' => $current], 'after' => ['role' => $data['role']]]);

        return response()->json(['data' => $this->present($user->refresh())]);
    }

    /** Removes the staff access; the account itself stays. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $this->guard($request, $user);

        $current = $this->roleOf($user);
        if ($current === AdminPermissions::SUPERADMIN) {
            $this->assertNotLastSuperadmin();
        }

        $user->removeRole(AdminPermissions::SUPERADMIN);
        $user->removeRole(AdminPermissions::SUPPORT);
        app(\App\Http\Services\Admin\Impersonation\ImpersonationService::class)->finishForStaff($user);

        $this->audit->record('team.removed', $user, ['reason' => $data['reason'], 'before' => ['role' => $current]]);

        return response()->json(['data' => ['id' => $user->id, 'removed' => true]]);
    }

    /**
     * Turns off the two-factor of ANOTHER team member (lost phone and lost recovery codes), closes all her sessions and
     * tells her by mail. Rules, safest first:
     *  - superadmin only (`admin.team.manage`) and a recent re-authentication (`admin.reauth`);
     *  - never on your own account (use Mi cuenta, which asks the password);
     *  - the target must be on the team and actually have two-factor on (409 otherwise);
     *  - to reset ANOTHER SUPERADMIN you must have two-factor on yourself (403 `two_factor_required_to_reset`): the
     *    re-authentication that opened this action then included a TOTP code, so a stolen password and token alone
     *    cannot strip the protection of the other superadmins.
     * A reset by hand, with no one else to do it, remains the `admin:two-factor-reset` console command.
     */
    public function resetTwoFactor(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $this->guard($request, $user);

        if ($user->hasRole(AdminPermissions::SUPERADMIN) && ! $request->user()->hasAdminTwoFactor()) {
            return response()->json(['message' => 'Turn on your own two-factor authentication to reset a superadmin\'s.', 'code' => 'two_factor_required_to_reset'], 403);
        }
        if (! $user->hasAdminTwoFactor()) {
            return response()->json(['message' => 'Two-factor authentication is not active.', 'code' => 'two_factor_not_enabled'], 409);
        }

        $closed = DB::transaction(function () use ($user) {
            $this->twoFactor->disable($user);

            return $this->security->revokeTokens($user);
        });
        $user->unsetRelation('adminTwoFactor');

        $this->audit->record('account.two_factor_reset_by_admin', $user, ['reason' => $data['reason'], 'after' => ['sessionsClosed' => $closed]]);
        $this->security->notify($user, $user, 'two_factor_reset_by_admin', ['actor' => $request->user()->name]);

        return response()->json(['data' => $this->present($user->refresh()) + ['sessionsClosed' => $closed]]);
    }

    /** Not yourself, and only people who are on the team. */
    private function guard(Request $request, User $user): void
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => ['cannot_change_self']]);
        }
        abort_unless($user->hasAnyRole(AdminPermissions::STAFF_ROLES), 404);
    }

    private function assertNotLastSuperadmin(): void
    {
        if (User::query()->role(AdminPermissions::SUPERADMIN)->count() <= 1) {
            throw ValidationException::withMessages(['user' => ['last_superadmin']]);
        }
    }

    private function roleOf(User $user): ?string
    {
        return collect(AdminPermissions::STAFF_ROLES)->first(fn ($r) => $user->hasRole($r));
    }

    private function present(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $this->roleOf($user), 'twoFactorEnabled' => $user->hasAdminTwoFactor(), 'createdAt' => $user->created_at?->toIso8601String()];
    }
}
