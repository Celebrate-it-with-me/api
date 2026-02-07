<?php

namespace App\Providers;

use App\Events\CommentCreated;
use App\Events\EventNotificationEvent;
use App\Events\GooglePlacePhotosQueued;
use App\Events\LogActivityEvent;
use App\Events\ResetPasswordEvent;
use App\Events\SuggestedMusicCreated;
use App\Events\UserLoggedInEvent;
use App\Events\UserLoggedOutEvent;
use App\Events\UserRegistered;
use App\Listeners\DispatchProcessGooglePlacePhotos;
use App\Listeners\LogActivityListener;
use App\Listeners\SendCommentCreatedNotification;
use App\Listeners\SendConfirmationEmail;
use App\Listeners\SendEventNotification;
use App\Listeners\SendResetPasswordLink;
use App\Listeners\SendSuggestedMusicCreatedNotification;
use App\Listeners\UserLoggedInListener;
use App\Listeners\UserLoggedOutListener;
use App\Models\GuestCompanion;
use App\Observers\GuestCompanionObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        UserLoggedInEvent::class => [
            UserLoggedInListener::class,
        ],

        UserLoggedOutEvent::class => [
            UserLoggedOutListener::class,
        ],

        UserRegistered::class => [
            SendConfirmationEmail::class,
        ],

        ResetPasswordEvent::class => [
            SendResetPasswordLink::class,
        ],

        GooglePlacePhotosQueued::class => [
            DispatchProcessGooglePlacePhotos::class
        ],

        LogActivityEvent::class => [
            LogActivityListener::class,
        ],

        SuggestedMusicCreated::class => [
            SendSuggestedMusicCreatedNotification::class
        ],

        CommentCreated::class => [
            SendCommentCreatedNotification::class
        ],

        EventNotificationEvent::class => [
            SendEventNotification::class
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        GuestCompanion::observe(GuestCompanionObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
