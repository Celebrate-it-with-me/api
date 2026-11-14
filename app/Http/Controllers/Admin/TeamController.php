<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Account\AccountSecurity;
use App\Http\Services\Admin\Account\TwoFactorService;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Http\Services\Admin\Team\TeamInvitations;
use App\Http\Services\Admin\Team\TeamMember;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The people who can open the admin panel, and the invitations still waiting (docs/plans/admin-team-contract.md).
 * Only a superadmin gets here (`admin.team.manage`). The `{id}` of a route is a user id, except in the two
 * `invitation` routes, where it is the id of an invited member (AdminInvitation::MEMBER_ID_OFFSET + invitation id).
 */
class TeamController extends Controller
{
    public function __construct(
        private readonly AdminAudit $audit,
        private readonly AccountSecurity $security,
        private readonly TwoFactorService $twoFactor,
        private readonly TeamInvitations $invitations,
    ) {}

    /** Active first, then invited, then suspended; each group by name. */
    public function index(): JsonResponse
    {
        $staff = User::query()->role(AdminPermissions::STAFF_ROLES)->with(['roles', 'adminTwoFactor'])->orderBy('name')->get();
        $invited = AdminInvitation::query()->orderBy('name')->orderBy('email')->get();

        $members = collect()
            ->concat($staff->reject(fn (User $u) => $u->admin_suspended_at)->map(fn (User $u) => TeamMember::fromUser($u)))
            ->concat($invited->map(fn (AdminInvitation $i) => TeamMember::fromInvitation($i)))
            ->concat($staff->filter(fn (User $u) => $u->admin_suspended_at)->map(fn (User $u) => TeamMember::fromUser($u)));

        return response()->json(['data' => $members->values()]);
    }

    /** Invites a person by mail. She gets the staff role only when she accepts. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(AdminPermissions::STAFF_ROLES)],
            'name' => ['nullable', 'string', 'max:255'],
            'reason' => AdminAudit::reasonRule(),
        ]);

        [$invitation, $mailSent] = $this->invitations->invite($request->user(), $data['email'], $data['name'] ?? null, $data['role'], $data['reason']);

        return response()->json(['data' => TeamMember::fromInvitation($invitation), 'mailSent' => $mailSent], 201);
    }

    public function resendInvitation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'min:5', 'max:500']]);
        $invitation = TeamInvitations::findOrFail($id);

        [$invitation, $mailSent] = $this->invitations->resend($request->user(), $invitation, $data['reason'] ?? null);

        return response()->json(['data' => TeamMember::fromInvitation($invitation), 'mailSent' => $mailSent]);
    }

    public function revokeInvitation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $this->invitations->revoke(TeamInvitations::findOrFail($id), $data['reason']);

        return response()->json(['data' => null]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(AdminPermissions::STAFF_ROLES)], 'reason' => AdminAudit::reasonRule()]);
        $user = $this->member($request, $id);

        $current = TeamMember::role($user);
        if ($current === $data['role']) {
            return response()->json(['data' => TeamMember::fromUser($user)]);
        }
        $this->assertNotLastActiveSuperadmin($user);

        DB::transaction(function () use ($user, $data) {
            $user->removeRole(AdminPermissions::SUPERADMIN);
            $user->removeRole(AdminPermissions::SUPPORT);
            $user->assignRole($data['role']);
        });

        $this->audit->record('team.role_changed', $user, ['reason' => $data['reason'], 'before' => ['role' => $current], 'after' => ['role' => $data['role']]]);

        return response()->json(['data' => TeamMember::fromUser($user->refresh())]);
    }

    /** Removes the staff access; the account itself stays. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $user = $this->member($request, $id);

        $current = TeamMember::role($user);
        $this->assertNotLastActiveSuperadmin($user);

        $user->removeRole(AdminPermissions::SUPERADMIN);
        $user->removeRole(AdminPermissions::SUPPORT);
        $user->forceFill(['admin_suspended_at' => null])->save(); // a later invitation starts clean
        app(ImpersonationService::class)->finishForStaff($user);

        $this->audit->record('team.removed', $user, ['reason' => $data['reason'], 'before' => ['role' => $current]]);

        return response()->json(['data' => null]);
    }

    /** Takes the access away without deleting anything: every session of hers is closed and the panel refuses her. */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $user = $this->member($request, $id);

        if (! $user->admin_suspended_at) {
            $this->assertNotLastActiveSuperadmin($user);

            $closed = DB::transaction(function () use ($user) {
                $user->forceFill(['admin_suspended_at' => now()])->save();

                // Only her panel sessions: suspending the panel access does not sign her out of the organizers' app.
                return $this->security->revokeTokens($user, null, 'admin');
            });
            app(ImpersonationService::class)->finishForStaff($user);

            $this->audit->record('team.suspend', $user, ['reason' => $data['reason'], 'after' => ['role' => TeamMember::role($user), 'sessionsClosed' => $closed]]);
        }

        return response()->json(['data' => TeamMember::fromUser($user->refresh())]);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $user = $this->member($request, $id);

        if ($user->admin_suspended_at) {
            $user->forceFill(['admin_suspended_at' => null])->save();
            $this->audit->record('team.reactivate', $user, ['reason' => $data['reason'], 'after' => ['role' => TeamMember::role($user)]]);
        }

        return response()->json(['data' => TeamMember::fromUser($user->refresh())]);
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
    public function resetTwoFactor(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $user = $this->member($request, $id);

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

        return response()->json(['data' => TeamMember::fromUser($user->refresh()) + ['sessionsClosed' => $closed]]);
    }

    /** A person on the team (404 `member_not_found` otherwise, also for an invited id), and never yourself. */
    private function member(Request $request, string $id): User
    {
        $user = ctype_digit($id) ? User::query()->find((int) $id) : null;
        if (! $user || ! $user->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            throw new HttpResponseException(response()->json(['message' => 'This person is not on the team.', 'code' => 'member_not_found'], 404));
        }
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => ['cannot_change_self']]);
        }

        return $user;
    }

    /** The team always keeps one superadmin whose access is on: not the last one to remove, demote or suspend. */
    private function assertNotLastActiveSuperadmin(User $user): void
    {
        if ($user->hasRole(AdminPermissions::SUPERADMIN) && ! $user->admin_suspended_at
            && User::query()->role(AdminPermissions::SUPERADMIN)->whereNull('admin_suspended_at')->count() <= 1) {
            throw ValidationException::withMessages(['user' => ['last_superadmin']]);
        }
    }
}
