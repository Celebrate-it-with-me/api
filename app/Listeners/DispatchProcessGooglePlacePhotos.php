<?php

namespace App\Listeners;

use App\Events\GooglePlacePhotosQueued;
use App\Jobs\ProcessGooglePlacePhotos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

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
