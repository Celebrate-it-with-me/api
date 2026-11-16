<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Mail\ConfirmAccountEmail;
use App\Support\Mail\Outbox;
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

        $completedUrl = config('app.frontend_app.url') . '/confirm-email?confirm=' . urlencode($confirmUrl);

        Outbox::send($user->email, new ConfirmAccountEmail($user, $completedUrl));
    }
}
