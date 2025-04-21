<?php

namespace App\Http\Services\AppServices;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserSettingsServices
{
    /**
     * Updates the profile information of the authenticated user, including
     * name, phone, and avatar. If the user is not authenticated, an exception
     * will be thrown.
     *
     * @param Request $request The incoming HTTP request containing updated profile data.
     * @return bool Returns true if the profile update is successful, otherwise false.
     * @throws Exception If the user is not authenticated.
     */
    public function updateProfile(Request $request): bool
    {
        try {
            // Updating name, phone and avatar
            $user = $request->user();
            
            if (!$user) {
                throw new Exception('User not authenticated');
            }
            
            $user->name = $request->input('name');
            $user->phone = $request->input('phone');
            
            if ($request->hasFile('avatar')) {
                $user->avatar = $request->file('avatar')->store('avatars', 'public');
            }
            
            $user->save();
            
            return true;
        } catch (Exception $ex) {
            // Handle exception
            Log::error('Error updating profile: ' . $ex->getMessage());
            return false;
        }
    }
}
