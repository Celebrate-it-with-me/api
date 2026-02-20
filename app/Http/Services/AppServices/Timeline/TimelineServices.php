<?php

namespace App\Http\Services\AppServices\Timeline;

use App\Models\Events;
use App\Models\Timeline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class TimelineServices
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    
    /**
     * Retrieves a collection of timelines associated with a specific event,
     * sorted by their start time in ascending order.
     *
     * @param Events $event The event instance used to filter timelines.
     * @return Collection A collection of timelines associated with the specified event.
     */
    public function getTimelinesByEvent(Events $event): Collection
    {
        return Timeline::query()
            ->where('event_id', $event->id)
            ->orderBy('start_time')
            ->get();
    }
    
    /**
     * Creates a new timeline entry associated with a specific event.
     *
     * @param Events $event The event instance to associate with the new timeline.
     * @param array $data The data to populate the new timeline entry.
     * @return Timeline The newly created timeline instance.
     */
    public function createTimeline(Events $event, array $data): Timeline
    {
        return Timeline::query()
            ->create(['event_id' => $event->id, ...$data]);
    }
    
    /**
     * Updates the specified timeline associated with a given event using the provided data
     * and refreshes the timeline instance to reflect the latest state.
     *
     * @param Events $event The event instance related to the timeline being updated.
     * @param Timeline $timeline The timeline instance to be updated.
     * @param array $data An associative array containing the updated data for the timeline.
     * @return Timeline The updated timeline instance.
     */
    public function updateTimeline(Events $event, Timeline $timeline, array $data): Timeline
    {
        $timeline->update($data);
        $timeline->refresh();
        
        return $timeline;
    }
    
    /**
     * Deletes a specific timeline associated with the given event.
     *
     * @param Events $event The event instance associated with the timeline.
     * @param Timeline $timeline The timeline instance to be deleted.
     * @return void
     */
    public function deleteTimeline(Events $event, Timeline $timeline): void
    {
        $timeline->delete();
    }
    
}
