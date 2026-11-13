<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
    public function __construct(private readonly AdminAudit $audit) {}

    public function index(): JsonResponse
    {
        $staff = User::query()->role(AdminPermissions::STAFF_ROLES)->with('roles')->orderBy('name')->get();

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

        $this->audit->record('team.removed', $user, ['reason' => $data['reason'], 'before' => ['role' => $current]]);

        return response()->json(['data' => ['id' => $user->id, 'removed' => true]]);
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
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $this->roleOf($user), 'createdAt' => $user->created_at?->toIso8601String()];
    }
}
