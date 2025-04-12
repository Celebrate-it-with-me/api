<?php

namespace App\Http\Services\AppServices;

use App\Models\EventFeature;
use App\Models\Events;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Calculation\Logical\Boolean;

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
     * Getting filtered events.
     * @param string $query
     * @return Collection
     */
    public function getFilteredEvents(string $query): Collection
    {
        return Events::query()
            ->where('organizer_id', $this->request->user()->id)
            ->when($query, function ($subQuery) use ($query) {
                $subQuery->whereNested(function($subQuery) use ($query) {
                   $subQuery->where('event_name', 'like', '%' . $query . '%');
                   $subQuery->orWhere('event_description', 'like', '%' . $query . '%');
                });
            })
            ->get();
    }
    
    /**
     * Create user event.
     * @return Model|Builder
     * @throws Exception
     */
    public function create(): Model|Builder
    {
        Log::info('start date', [$this->request->input('startDate')]);
        
        $event = Events::query()->create([
            'event_name' => $this->request->input('eventName'),
            'event_description' => $this->request->input('eventDescription'),
            'start_date' => Carbon::createFromFormat('m/d/Y H:i', $this->request->input('startDate'))->toDateTimeString(),
            'end_date' => Carbon::createFromFormat('m/d/Y H:i', $this->request->input('endDate'))->toDateTimeString(),
            'organizer_id' => $this->request->user()->id,
            'status' => $this->request->input('status'),
            'custom_url_slug' => $this->request->input('customUrlSlug'),
            'visibility' => $this->request->input('visibility'),
        ]);
        
        if (!$event) {
            throw new Exception('Create event failed');
        }
        
        EventFeature::query()->create([
           'event_id' => $event->id,
            'save_the_date' => $this->request->input('saveTheDate') ?? false,
            'rsvp' => $this->request->input('rsvp') ?? false,
            'sweet_memories' => $this->request->input('sweetMemories') ?? false,
            'music' => $this->request->input('music') ?? false,
            'background_music' => $this->request->input('backgroundMusic') ?? false,
            'event_comments' => $this->request->input('eventComments') ?? false,
            'seats_accommodation' => $this->request->input('seatsAccommodation') ?? false,
            'preview' => $this->request->input('preview') ?? false,
            'budget' => $this->request->input('budget') ?? false,
            'analytics' => $this->request->input('analytics') ?? false,
        ]);
        
        return $event;
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
        $this->event->start_date = $this->request->input('startDate');
        $this->event->end_date = $this->request->input('endDate');
        $this->event->status = $this->request->input('status');
        $this->event->custom_url_slug = $this->request->input('customUrlSlug');
        $this->event->visibility = $this->request->input('visibility');
        $this->event->save();
        
        $this->event->eventFeature->save_the_date = $this->request->input('saveTheDate') ?? false;
        $this->event->eventFeature->rsvp = $this->request->input('rsvp') ?? false;
        $this->event->eventFeature->gallery = $this->request->input('gallery') ?? false;
        $this->event->eventFeature->music = $this->request->input('music') ?? false;
        $this->event->eventFeature->background_music = $this->request->input('backgroundMusic') ?? false;
        $this->event->eventFeature->event_comments = $this->request->input('eventComments') ?? false;
        $this->event->eventFeature->seats_accommodation = $this->request->input('seatsAccommodation') ?? false;
        $this->event->eventFeature->preview = $this->request->input('preview') ?? false;
        $this->event->eventFeature->budget = $this->request->input('eventBudget') ?? false;
        $this->event->eventFeature->analytics = $this->request->input('analytics') ?? false;
        $this->event->eventFeature->save();
        

        return $this->event;
    }
    
    /**
     * Delete user from db.
     * @param Events $event
     * @return bool
     */
    public function destroy(Events $event): bool
    {
        try {
            $event->delete();
            return true;
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return false;
        }
    }
}
