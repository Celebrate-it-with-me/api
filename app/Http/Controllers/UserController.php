<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Http\Services\UsersServices;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(private UsersServices $userService) {}

    /**
     * User index.
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        try {
            return $this->userService->getUsersWithPagination();
        } catch (Exception $e) {
            return response()->json(['message' => 'Unable to get the user list'], 409);
        }
    }

    /**
     * Create a new user.
     */
    public function store(CreateUserRequest $request): UserResource|JsonResponse
    {
        try {
            return UserResource::make($this->userService->create());
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage()], 409);
        }
    }

    /**
     * Get user.
     */
    public function show(User $user): UserResource|JsonResponse
    {
        try {
            return UserResource::make($user);
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage()], 409);
        }
    }

    /**
     * Get Logged user.
     */
    public function userInfo(Request $request): UserResource|JsonResponse
    {
        try {
            return UserResource::make($request->user());
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage()], 409);
        }
    }

    /**
     * Update user info.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource|JsonResponse
    {
        try {
            return UserResource::make($this->userService->update($user));
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage()], 409);
        }
    }

    /**
     * Remove User from database.
     */
    public function destroy(User $user): UserResource|JsonResponse
    {
        try {
            return UserResource::make($this->userService->destroy($user));
        } catch (Exception $e) {
            return response()->json(['message' => 'Ops something fail ' . $e->getMessage()], 409);
        }
    }
}
