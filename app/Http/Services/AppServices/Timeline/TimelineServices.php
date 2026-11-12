<?php

namespace App\Http\Services\AppServices\Timeline;

use App\Models\Events;
use App\Models\Timeline;
use Illuminate\Support\Collection;

class TimelineServices
{
    /**
     * Typical programs: [type, Spanish title, English title, minutes after the event starts, minutes it lasts].
     * They are a starting point; the organizer edits everything.
     */
    private const TYPICAL = [
        'quinceanera' => [
            ['ceremony', 'Misa de acción de gracias', 'Thanksgiving Mass', 0, 60],
            ['reception', 'Recepción', 'Reception', 90, 60],
            ['waltz', 'Vals', 'Waltz', 150, 20],
            ['dinner', 'Cena', 'Dinner', 180, 60],
            ['dance', 'Baile', 'Dancing', 240, 120],
            ['other', 'Cierre', 'Farewell', 360, null],
        ],
        'wedding' => [
            ['ceremony', 'Ceremonia', 'Ceremony', 0, 45],
            ['reception', 'Cóctel', 'Cocktail hour', 60, 60],
            ['dinner', 'Cena', 'Dinner', 120, 75],
            ['dance', 'Primer baile', 'First dance', 200, 15],
            ['dance', 'Fiesta', 'Party', 220, 150],
        ],
    ];

    /** The event's moments in order. @return Collection<int, Timeline> */
    public function getTimelinesByEvent(Events $event): Collection
    {
        return Timeline::query()->where('event_id', $event->id)->orderBy('start_time')->orderBy('id')->get();
    }

    public function create(Events $event, array $data): Timeline
    {
        return Timeline::query()->create(['event_id' => $event->id, ...$data]);
    }

    public function update(Timeline $timeline, array $data): Timeline
    {
        $timeline->update($data);

        return $timeline->refresh();
    }

    public function delete(Timeline $timeline): void
    {
        $timeline->delete();
    }

    /**
     * Fill an empty program with the typical one for the event's type, counted from its start time.
     * Never touches a program that already has moments.
     *
     * @return Collection<int, Timeline>|null null when the event already has moments
     */
    public function createTypical(Events $event): ?Collection
    {
        if (Timeline::query()->where('event_id', $event->id)->exists()) {
            return null;
        }

        $slug = $event->eventType?->slug === 'wedding' ? 'wedding' : 'quinceanera';
        $english = ($event->locale ?? 'es') === 'en';
        $start = $event->start_date->copy();

        foreach (self::TYPICAL[$slug] as [$type, $es, $en, $after, $lasts]) {
            Timeline::query()->create([
                'event_id' => $event->id,
                'type' => $type,
                'title' => $english ? $en : $es,
                'start_time' => $start->copy()->addMinutes($after),
                'end_time' => $lasts ? $start->copy()->addMinutes($after + $lasts) : null,
            ]);
        }

        return $this->getTimelinesByEvent($event);
    }
}
