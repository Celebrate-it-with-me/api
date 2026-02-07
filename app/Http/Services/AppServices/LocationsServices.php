<?php

namespace App\Http\Services\AppServices;

use App\Models\EventLocation;
use App\Models\Events;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LocationsServices
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Retrieve a paginated list of event locations for a specific event.
     *
     * This method fetches event location records associated with a given event,
     * allowing optional filtering based on a search value. The results are paginated
     * with customizable page size and page number.
     *
     * @param Events $event The event for which locations are being retrieved.
     * @return Collection A paginated collection of event locations.
     */
    public function getEventLocation(Events $event): ?EventLocation
    {
        $searchValue = $this->request->input('searchValue');

        return EventLocation::query()
            ->with('eventLocationImages')
            ->where('event_id', $event->id)
            ->when($searchValue, function ($query) use ($searchValue) {
                $query->where(function ($q) use ($searchValue) {
                    $q->where('name', 'like', "%{$searchValue}%")
                        ->orWhere('address', 'like', "%{$searchValue}%")
                        ->orWhere('city', 'like', "%{$searchValue}%");
                });
            })
            ->first();
    }
}
