<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Http\Services\Auth\EmailVerificationService;

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
        app(EmailVerificationService::class)->send($event->user);
    }
}
