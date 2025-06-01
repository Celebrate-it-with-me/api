<?php

namespace App\Http\Services\AppServices;

use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Model;

class UserPreferencesServices
{
    /**
     * Getting user Preferences
     */
    public function getUserPreferences($user)
    {
        return $user->preferences;
    }

    /**
     * Updates or creates user preferences based on the provided request data.
     *
     * @param  mixed  $user  The user object whose preferences are to be updated.
     * @return Model The updated or created user preferences.
     */
    public function updateUserPreferences($user, $validated): Model
    {
        return UserPreference::query()->updateOrCreate(
            [
                'user_id' => $user->id,
            ],
            [
                'language' => $validated['language'] ?? null,
                'timezone' => $validated['timezone'] ?? null,
                'visual_theme' => $validated['visualTheme'] ?? null,
                'date_format' => $validated['dateFormat'] ?? null,

                'notify_by_email' => $validated['notifyByEmail'] ?? null,
                'notify_by_sms' => $validated['notifyBySms'] ?? null,
                'smart_tips' => $validated['smartTips'] ?? null,
            ]
        );
    }
}
