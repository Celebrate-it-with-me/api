<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Services\AppServices\UserSettingsServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSettingsController extends Controller
{
    private UserSettingsServices $userSettingsServices;
    
    public function __construct(UserSettingsServices $userSettingsServices)
    {
        $this->userSettingsServices = $userSettingsServices;
    }
    
    /**
     * Updates the user's profile based on the provided request data.
     *
     * @param Request $request The HTTP request containing profile update data.
     * @return JsonResponse Returns a JSON response indicating success or failure of the operation.
     * @throws \Exception
     */
    public function updateProfile(Request $request): JsonResponse
    {
        if ($this->userSettingsServices->updateProfile($request)) {
            return response()->json(['message' => 'Profile updated successfully.']);
        }
        
        return response()->json(['message' => 'Failed to update profile.'], 500);
    }
    
    /**
     * Retrieves the currently authenticated user's information from the request.
     *
     * @param Request $request The HTTP request containing user authentication data.
     * @return JsonResponse Returns a JSON response with the user information on success, or an error message if the user is not authenticated.
     * @throws \Exception
     */
    public function getUser(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'User not authenticated.'], 401);
        }
        
        return response()->json([
            'message' => 'User retrieved successfully.',
            'data' => [
                'user' => $user,
            ]
        ]);
    }
    
    /**
     * Updates the user's password based on the provided request data.
     *
     * @param Request $request The HTTP request containing password update data.
     * @return JsonResponse Returns a JSON response indicating success or failure of the operation.
     * @throws \Exception
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $request->merge([
            'newPassword_confirmation' => $request->input('newPasswordConfirmation')
        ]);
        
        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|confirmed|min:8',
        ]);
        
        if ($this->userSettingsServices->updatePassword($request)) {
            return response()->json(['message' => 'Password updated successfully.']);
        }
        
        return response()->json(['message' => 'Failed to update password.'], 500);
    }
    
}
