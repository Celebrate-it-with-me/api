<?php

namespace App\Events;

use App\Models\EventLocation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GooglePlacePhotosQueued implements ShouldQueue
{
    use Dispatchable, SerializesModels;

    public EventLocation $eventLocation;
    public array $photosReferences;
    
    /**
     * Create a new event instance.
     */
    public function __construct(EventLocation $eventLocation, array $photosReferences)
    {
        $this->eventLocation = $eventLocation;
        $this->photosReferences = $photosReferences;
    }
}
