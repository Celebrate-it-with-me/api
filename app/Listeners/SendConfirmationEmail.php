<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Mail\ConfirmAccountEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendConfirmationEmail
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
    public function handle(UserRegistered $event): void
    {
        $user = $event->user;
        
        $confirmUrl = URL::temporarySignedRoute(
            'auth.confirm.email',
            now()->addDay(),
            ['user' => $user->id]
        );
        
        $completedUrl = config('app.frontend_app.url'). 'confirm-email?confirm=' . urlencode($confirmUrl);
        
        Mail::to($user->email)->send(new ConfirmAccountEmail($user, $completedUrl));
    }
}
