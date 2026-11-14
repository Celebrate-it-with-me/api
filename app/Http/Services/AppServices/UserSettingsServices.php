<?php

namespace App\Http\Services\AppServices;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
    
    /**
     * Updates the password of the authenticated user. The new password is hashed
     * before being saved. Throws an exception if the user is not authenticated.
     *
     * @param Request $request The incoming HTTP request containing the new password.
     * @return bool Returns true if the password update is successful, otherwise false.
     * @throws Exception If the user is not authenticated.
     */
    public function updatePassword(Request $request): bool
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                throw new Exception('User not authenticated');
            }
            
            // A stolen session cannot guess the current password without limit.
            $key = 'app-password:' . $user->id;
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
            }

            if (!Hash::check($request->input('currentPassword'), $user->password)) {
                RateLimiter::hit($key, 15 * 60);

                throw ValidationException::withMessages(['currentPassword' => 'Current password is incorrect.']);
            }

            RateLimiter::clear($key);

            $user->password = Hash::make($request->input('newPassword'));
            $user->password_changed_at = now();
            $user->save();

            // Changing the password closes every other API session (the one in use stays).
            $current = $user->currentAccessToken();
            $user->tokens()
                ->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))
                ->delete();
            
            return true;
        } catch (ValidationException|ThrottleRequestsException $ex) {
            throw $ex;
        } catch (Exception $ex) {
            // Handle exception
            Log::error('Error updating password: ' . $ex->getMessage());
            return false;
        }
    }
}
