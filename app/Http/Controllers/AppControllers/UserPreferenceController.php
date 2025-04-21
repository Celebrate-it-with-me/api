<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\UserPreferencesResource;
use App\Http\Services\AppServices\UserPreferencesServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    private UserPreferencesServices $userPreferenceServices;
    
    public function __construct(UserPreferencesServices $userPreferenceServices)
    {
        $this->userPreferenceServices = $userPreferenceServices;
    }
    
    /**
     * Handle the retrieval and display of user preferences.
     *
     * @param Request $request The HTTP request instance containing user authentication data.
     *
     * @return JsonResponse|UserPreferencesResource Returns a JSON response or a UserPreferencesResource
     *                                               depending on the success of the operation.
     */
    public function showPreferences(Request $request): JsonResponse | UserPreferencesResource
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'User not authenticated.'], 401);
        }
        
        $preferences = $this->userPreferenceServices->getUserPreferences($user);
        
        if ($preferences) {
            return UserPreferencesResource::make($preferences);
        }
        
        return response()->json(['message' => 'User preferences not found.'], 404);
    }
    
    /**
     * Handle the update of user preferences.
     *
     * Validates the incoming request data and updates the preferences for the authenticated user.
     *
     * @param Request $request The HTTP request instance containing user data and preferences input.
     *
     * @return JsonResponse|UserPreferencesResource Returns a JSON response or a UserPreferencesResource
     *                                               depending on the success of the update operation.
     */
    public function updatePreferences(Request $request): JsonResponse | UserPreferencesResource
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'language' => 'string|nullable',
            'timezone' => 'string|nullable',
            'darkMode' => 'boolean|nullable',
            'dateFormat' => 'string|nullable',
            'notifyByEmail' => 'boolean|nullable',
            'notifyBySms' => 'boolean|nullable',
            'smartTips' => 'boolean|nullable',
        ]);
        
        
        if (!$user) {
            return response()->json(['message' => 'User not authenticated.'], 401);
        }
        
        $preferences = $this->userPreferenceServices->updateUserPreferences($user, $validated);
        
        if ($preferences) {
            return UserPreferencesResource::make($preferences);
        }
        
        return response()->json(['message' => 'User preferences not found.'], 404);
    }
    
}
