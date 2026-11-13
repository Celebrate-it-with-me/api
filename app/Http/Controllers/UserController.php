<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\UsersServices;
use App\Models\User;
use App\Http\Services\Admin\Account\TwoFactorService;
use App\Support\AdminPermissions;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private UsersServices $userService, private AdminAudit $audit){}

    /**
     * Create a new user.
     * @param CreateUserRequest $request
     * @return UserResource|JsonResponse
     */
    public function store(CreateUserRequest $request): UserResource|JsonResponse
    {
        try {
            $user = $this->userService->create();
            $this->audit->record('user.create', $user, ['after' => $user->only(['name', 'email'])]);

            return UserResource::make($user);
        } catch(Exception $e) {
            return response()->json(['message' => 'Ops something fail '.$e->getMessage()],409);
        }
    }

    /**
     * Get Logged user.
     * @param Request $request
     * @return UserResource|JsonResponse
     */
    public function userInfo(Request $request): UserResource|JsonResponse
    {
        try {
            $user = $request->user();

            // The panel needs to know what this account may do, to show only that.
            return response()->json(['data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->intersect(AdminPermissions::STAFF_ROLES)->values(),
                'permissions' => $user->getAllPermissions()->pluck('name')->filter(fn ($p) => str_starts_with($p, 'admin.'))->values(),
                'security' => [
                    'twoFactorEnabled' => $user->hasAdminTwoFactor(),
                    // The server requires it for her role and she has not enrolled: the panel sends her to set it up.
                    'twoFactorSetupRequired' => TwoFactorService::requiredFor($user) && ! $user->hasAdminTwoFactor(),
                ],
            ]]);
        } catch(Exception $e) {
            return response()->json(['message' => 'Ops something fail '.$e->getMessage()],409);
        }
    }

    /**
     * Update user info.
     * @param UpdateUserRequest $request
     * @param User $user
     * @return UserResource|JsonResponse
     */
    public function update(UpdateUserRequest $request, User $user): UserResource|JsonResponse
    {
        try {
            $before = $user->only(['name', 'email']);
            $updated = $this->userService->update($user);
            $this->audit->record('user.update', $updated, ['before' => $before, 'after' => $updated->only(['name', 'email'])]);

            return UserResource::make($updated);
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail '.$e->getMessage()],409);
        }
    }

    /**
     * Remove User from database.
     * @param User $user
     * @return UserResource|JsonResponse
     */
    public function destroy(Request $request, User $user): UserResource|JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        abort_if($user->hasAnyRole(AdminPermissions::STAFF_ROLES), 422, 'Staff accounts are removed from Team and roles first.');

        try {
            $before = $user->only(['name', 'email']);
            $deleted = $this->userService->destroy($user);
            $this->audit->record('user.delete', ['user', $deleted->id], ['user_id' => $deleted->id, 'reason' => $data['reason'], 'before' => $before]);

            return UserResource::make($deleted);
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail '.$e->getMessage()],409);
        }
    }
}
