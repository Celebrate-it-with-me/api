<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\EventLifecycleService;
use App\Models\Events;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Status changes of an event. Access is checked by the `event.access` middleware on the route group. */
class EventLifecycleController extends Controller
{
    public function __construct(private readonly EventLifecycleService $lifecycle) {}

    public function publish(Request $request, Events $event): EventResource
    {
        $stage = $request->validate(['stage' => ['nullable', Rule::in([Events::STAGE_SAVE_THE_DATE, Events::STAGE_INVITATION])]])['stage'] ?? Events::STAGE_INVITATION;

        return EventResource::make($this->lifecycle->publish($event, $request->user(), $stage));
    }

    public function unpublish(Request $request, Events $event): EventResource
    {
        return EventResource::make($this->lifecycle->unpublish($event, $request->user()));
    }

    public function archive(Request $request, Events $event): EventResource
    {
        return EventResource::make($this->lifecycle->archive($event, $request->user()));
    }

    public function restore(Request $request, Events $event): EventResource
    {
        return EventResource::make($this->lifecycle->restore($event, $request->user()));
    }
}
