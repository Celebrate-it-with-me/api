<?php

namespace App\Listeners;

use App\Events\GooglePlacePhotosQueued;
use App\Jobs\ProcessGooglePlacePhotos;

class DispatchProcessGooglePlacePhotos
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
    public function handle(GooglePlacePhotosQueued $event): void
    {
        ProcessGooglePlacePhotos::dispatch($event->eventLocation, $event->photosReferences);
    }
}
