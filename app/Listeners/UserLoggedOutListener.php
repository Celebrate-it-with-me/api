<?php

namespace App\Listeners;

use App\Events\UserLoggedOutEvent;
use App\Models\UserLoginSession;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UserLoggedOutListener
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
    public function handle(UserLoggedOutEvent $event): void
    {
        try {
            $lastEvent = UserLoginSession::query()
                ->where('user_id', $event->user->id)
                ->whereNull('logout_time')
                ->orderBy('id', 'desc')
                ->first();
            
            if ($lastEvent) {
                $lastEvent->logout_time = now();
                $lastEvent->save();
            }
            
        } catch(Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
