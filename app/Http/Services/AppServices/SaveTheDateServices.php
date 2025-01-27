<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\SaveTheDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SaveTheDateServices
{
    protected Request $request;
    protected SaveTheDate $saveTheDate;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->saveTheDate = new SaveTheDate();
    }
    
    /**
     * Get event save the date.
     * @param Events $event
     * @return mixed
     */
    public function getEventSTD(Events $event): mixed
    {
        return $event->saveTheDate;
    }

    public function createEventSTD(Events $event)
    {
        // I need to save the image
        
        return SaveTheDate::query()->create([
            'event_id' => $event->id,
            'std_title' => $this->request->input('stdTitle'),
            'std_subtitle' => $this->request->input('stdSubTitle'),
            'image_url' => $this->request->input('image'),
            'background_color' => $this->request->input('backgroundColor'),
            'use_countdown' => $this->request->input('useCountdown'),
            'use_add_to_calendar' => $this->request->input('useAddToCalendar'),
            'is_enabled' => $this->request->input('isEnabled'),
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
