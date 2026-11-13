<?php

namespace App\Http\Services\AppServices;

use App\Models\DressCode;
use App\Models\EventLocation;
use App\Models\Events;
use App\Models\Guest;
use App\Models\Timeline;

/**
 * Where an event is in its first steps: who is on the list, what details are filled in, whether it is shared.
 * Read from the real data, so a step completes itself when the work is done anywhere in the app.
 */
class Onboarding
{
    /** @return array<string, mixed> */
    public function summary(Events $event): array
    {
        $guests = Guest::query()->where('event_id', $event->id)->count();
        $program = Timeline::query()->where('event_id', $event->id)->exists();
        $places = EventLocation::query()->where('event_id', $event->id)->exists();
        $dress = DressCode::query()->where('event_id', $event->id)->exists();
        $published = $event->status === 'published';

        $steps = [
            'event' => true,
            'guests' => $guests > 0,
            'details' => $program || $places || $dress,
            'share' => $published,
        ];

        return [
            'steps' => $steps,
            'done' => count(array_filter($steps)),
            'total' => count($steps),
            'guests' => $guests,
            'details' => ['program' => $program, 'places' => $places, 'dressCode' => $dress],
            'status' => $event->status,
            'stage' => $event->invitation_stage,
        ];
    }
}
