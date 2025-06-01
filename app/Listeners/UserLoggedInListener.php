<?php

namespace App\Listeners;

use App\Events\UserLoggedInEvent;
use App\Models\UserLoginSession;
use Exception;
use Illuminate\Support\Facades\Log;

class UserLoggedInListener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(UserLoggedInEvent $event): void
    {
        try {
            UserLoginSession::query()->create([
                'user_id' => $event->user->id,
                'login_time' => now(),
                'ip_address' => $event->request->ip(),
                'browser' => $event->agentData['browser'] ?? null,
                'platform' => $event->agentData['platform'] ?? null,
                'device' => $event->agentData['device'] ?? null,
                /* 'location' => , Todo create a new feature to work on this */
                'logout_time' => null,
            ]);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
