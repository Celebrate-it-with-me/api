<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class EventsServices
{
    protected Request $request;
    protected Events $event;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->event = new Events();
    }

    /**
     * Get user logged events.
     * @return Collection
     */
    public function getUserEvents(): Collection
    {
        return Events::query()
            ->where('organizer_id', $this->request->user()->id)
            ->get();
    }

    /**
     * Create user event.
     * @return Model|Builder
     */
    public function create(): Model|Builder
    {
        return Events::query()->create([
            'event_name' => $this->request->input('eventName'),
            'event_description' => $this->request->input('eventDescription'),
            'event_date' => $this->request->input('eventDate'),
            'organizer_id' => $this->request->user()->id,
            'status' => $this->request->input('status'),
            'custom_url_slug' => $this->request->input('customUrlSlug'),
            'visibility' => $this->request->input('visibility'),
        ]);
    }

    /**
     * Update user event info.
     * @param Events $event
     * @return Events
     */
    public function update(Events $event): Events
    {
        $this->event = $event;

        $this->event->event_name = $this->request->input('eventName');
        $this->event->event_description = $this->request->input('eventDescription');
        $this->event->event_date = $this->request->input('eventDate');
        $this->event->status = $this->request->input('status');
        $this->event->custom_url_slug = $this->request->input('customUrlSlug');
        $this->event->visibility = $this->request->input('visibility');

        $this->event->save();

        return $this->event;
    }

    /**
     * Delete user from db.
     * @param Events $event
     * @return Events
     */
    public function destroy(Events $event): Events
    {
        $eventSaved = clone $event;

        $event->delete();

        return $eventSaved;
    }
}
