<?php

namespace App\Http\Controllers\Admin\Users;

use App\Events\ResetPasswordEvent;
use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Users\AdminUserService;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserAdminController extends Controller
{
    public function __construct(private readonly AdminUserService $users, private readonly AdminAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(['staff', 'customer'])],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'internal' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $page = $this->users->paginate($f);

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => $this->users->row($u))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $this->users->profile($user)]);
    }

    public function passwordReset(User $user): JsonResponse
    {
        if ($user->anonymized_at) {
            throw ValidationException::withMessages(['user' => ['account_deleted']]);
        }

        ResetPasswordEvent::dispatch($user);
        $this->audit->record('user.password_reset', $user);

        return response()->json(['message' => 'Reset email sent.']);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $this->guardTarget($request, $user);

        if ($user->suspended_at) {
            throw ValidationException::withMessages(['user' => ['already_suspended']]);
        }

        $this->users->suspend($user, $request->user(), $data['reason']);
        $this->audit->record('user.suspend', $user, ['reason' => $data['reason'], 'before' => ['suspended' => false], 'after' => ['suspended' => true]]);

        return response()->json(['data' => $this->users->profile($user->refresh())]);
    }

    public function unsuspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);

        if (! $user->suspended_at) {
            throw ValidationException::withMessages(['user' => ['not_suspended']]);
        }

        $this->users->unsuspend($user);
        $this->audit->record('user.unsuspend', $user, ['reason' => $data['reason'], 'before' => ['suspended' => true], 'after' => ['suspended' => false]]);

        return response()->json(['data' => $this->users->profile($user->refresh())]);
    }

    public function export(User $user)
    {
        $payload = $this->users->export($user);
        $this->audit->record('user.export', $user);

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="user-' . $user->id . '-export.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => AdminAudit::reasonRule(),
            'confirmEmail' => ['required', 'string'],
        ]);
        $this->guardTarget($request, $user);

        if ($user->anonymized_at) {
            throw ValidationException::withMessages(['user' => ['already_deleted']]);
        }
        if (strcasecmp(trim($data['confirmEmail']), $user->email) !== 0) {
            throw ValidationException::withMessages(['confirmEmail' => ['email_mismatch']]);
        }

        $before = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];
        $result = $this->users->anonymize($user);
        $this->audit->record('user.delete', $user, [
            'reason' => $data['reason'], 'before' => $before,
            'after' => ['anonymized' => true, 'archivedEvents' => $result['archivedEvents']],
        ]);

        return response()->json(['data' => $this->users->profile($user->refresh()), 'archivedEvents' => $result['archivedEvents']]);
    }

    /** Neither staff accounts nor the actor themselves can be suspended or deleted from here. */
    private function guardTarget(Request $request, User $user): void
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => ['cannot_target_self']]);
        }
        if ($user->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            throw ValidationException::withMessages(['user' => ['cannot_target_staff']]);
        }
    }
}
