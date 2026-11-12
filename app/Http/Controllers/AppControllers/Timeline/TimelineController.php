<?php

namespace App\Http\Controllers\AppControllers\Timeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\Timeline\TimelineStoreRequest;
use App\Http\Services\AppServices\Timeline\TimelineServices;
use App\Models\Events;
use App\Models\Timeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/** The program of the day. Access to the event and to the moment is checked by `event.access` on the routes. */
class TimelineController extends Controller
{
    public function __construct(private readonly TimelineServices $timelines) {}

    public function index(Events $event): JsonResponse
    {
        return $this->list($this->timelines->getTimelinesByEvent($event));
    }

    public function store(TimelineStoreRequest $request, Events $event): JsonResponse
    {
        $moment = $this->timelines->create($event, $request->moment());

        return response()->json(['data' => $moment->toMoment()], 201);
    }

    public function update(TimelineStoreRequest $request, Events $event, Timeline $timeline): JsonResponse
    {
        return response()->json(['data' => $this->timelines->update($timeline, $request->moment())->toMoment()]);
    }

    public function destroy(Events $event, Timeline $timeline): JsonResponse
    {
        $this->timelines->delete($timeline);

        return response()->json(['message' => 'Moment removed.']);
    }

    /** "Empezar con un programa típico": only on an empty program. */
    public function typical(Events $event): JsonResponse
    {
        $created = $this->timelines->createTypical($event);

        if ($created === null) {
            return response()->json(['message' => 'The program already has moments.'], 422);
        }

        return $this->list($created, 201);
    }

    private function list(Collection $moments, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $moments->map->toMoment()->values()], $status);
    }
}
