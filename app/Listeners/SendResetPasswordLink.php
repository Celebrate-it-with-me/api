<?php

namespace App\Listeners;

use App\Events\ResetPasswordEvent;
use App\Mail\ResetPasswordEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

class SendResetPasswordLink
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
    public function handle(ResetPasswordEvent $event): void
    {
        $user = $event->user;

        // Send the reset password link to the user
        $confirmUrl = URL::temporarySignedRoute(
            'auth.check.password',
            now()->addMinutes((int) config('auth.passwords.users.expire', 60)),
            ['user' => $user->id, 'token' => Password::broker()->createToken($user)]
        );

        $completedUrl = config('app.frontend_app.url') . '/confirm-reset?confirm=' . urlencode($confirmUrl);

        Mail::to($user->email)->send(new ResetPasswordEmail($user, $completedUrl));
    }
}
