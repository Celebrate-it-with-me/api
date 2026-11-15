<?php

namespace App\Http\Controllers\AppControllers\EventActivity;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\EventActivity\EventActivityResource;
use App\Models\Events;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventActivityController extends Controller
{
    /**
     * Get the latest 10 activities for the dashboard.
     */
    public function dashboardLogs(Events $event): AnonymousResourceCollection
    {
        return EventActivityResource::collection(
            $event->activities()
                ->with('actor')
                ->latest()
                ->limit(10)
                ->get()
        );
    }
}
