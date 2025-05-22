<?php

namespace App\Http\Services\AppServices;

use App\Http\Services\Logger\EventActivityLogger;
use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Auth;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

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
    public function getUserEvents(): array
    {
        $user = $this->request->user();
        
        if (!$user) {
            throw new Exception('User not authenticated');
        }
        
        if ($user->last_active_event_id) {
            $lastActiveEvent = Events::query()
                ->where('id', $user->last_active_event_id)
                ->where('organizer_id', $user->id)
                ->first();
        }
        
        $events = Events::query()
            ->where('organizer_id', $user->id)
            ->get();
        
        return [
            $events,
            $lastActiveEvent ?? null,
        ];
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
        $event = Events::query()->create([
            'event_name' => $this->request->input('eventName'),
            'event_description' => $this->request->input('eventDescription'),
            'event_type_id' => $this->request->input('eventType'),
            'event_plan_id' => $this->request->input('eventPlan') ?? 3,
            'start_date' => Carbon::createFromFormat('m/d/Y H:i', $this->request->input('startDate'))->toDateTimeString(),
            'end_date' => Carbon::createFromFormat('m/d/Y H:i', $this->request->input('endDate'))->toDateTimeString(),
            'organizer_id' => $this->request->user()->id,
            'status' => $this->request->input('status'),
            'custom_url_slug' => $this->request->input('customUrlSlug') ?? Str::slug(
                    $this->request->input('eventName')
                ) . '-' . (Events::query()->max('id') + 1),
            'visibility' => $this->request->input('visibility'),
        ]);
        
        if (!$event) {
            throw new Exception('Create event failed');
        }
        
        EventFeature::query()->create([
            'event_id' => $event->id,
            'save_the_date' => $this->request->input('saveTheDate') ?? false,
            'rsvp' => $this->request->input('rsvp') ?? false,
            'menu' => $this->request->input('menu') ?? false,
            'sweet_memories' => $this->request->input('sweetMemories') ?? false,
            'music' => $this->request->input('music') ?? false,
            'background_music' => $this->request->input('backgroundMusic') ?? false,
            'event_comments' => $this->request->input('eventComments') ?? false,
            'location' => $this->request->input('location') ?? false,
            'seats_accommodation' => $this->request->input('seatsAccommodation') ?? false,
            'preview' => $this->request->input('preview') ?? false,
            'budget' => $this->request->input('budget') ?? false,
            'analytics' => $this->request->input('analytics') ?? false,
        ]);
        
        $actor = request()->user();
        
        if ($actor) {
            $actor->last_active_event_id = $event->id;
            $actor->save();
        }
        
        EventActivityLogger::log(
            $event->id,
            'event_created',
            $actor ?? null,
            $event,
            [
                'event_name' => $event->event_name,
                'event_description' => $event->event_description,
                'event_type_id' => $event->event_type_id,
                'event_plan_id' => $event->event_plan_id,
                'start_date' => $event->start_date,
                'end_date' => $event->end_date,
                'status' => $event->status,
                'custom_url_slug' => $event->custom_url_slug,
                'visibility' => $event->visibility,
            ]
        );
        
        EventUserRole::query()->firstOrCreate([
            'event_id' => $event->id,
            'user_id' => $this->request->user()->id,
        ], [
            'role' => Role::query()->where('name', 'owner')->first()->id,
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
        $this->event->event_type_id = $this->request->input('eventType');
        $this->event->event_plan_id = $this->request->input('eventPlan') ?? 3;
        $this->event->start_date = $this->request->input('startDate');
        $this->event->end_date = $this->request->input('endDate');
        $this->event->status = $this->request->input('status');
        $this->event->custom_url_slug = $this->request->input('customUrlSlug');
        $this->event->visibility = $this->request->input('visibility');
        $this->event->save();
        
        $this->event->eventFeature->save_the_date = $this->request->input('saveTheDate') ?? false;
        $this->event->eventFeature->rsvp = $this->request->input('rsvp') ?? false;
        $this->event->eventFeature->menu = $this->request->input('menu') ?? false;
        $this->event->eventFeature->sweet_memories = $this->request->input('sweetMemories') ?? false;
        $this->event->eventFeature->music = $this->request->input('music') ?? false;
        $this->event->eventFeature->background_music = $this->request->input('backgroundMusic') ?? false;
        $this->event->eventFeature->event_comments = $this->request->input('eventComments') ?? false;
        $this->event->eventFeature->location = $this->request->input('location') ?? false;
        $this->event->eventFeature->seats_accommodation = $this->request->input('seatsAccommodation') ?? false;
        $this->event->eventFeature->preview = $this->request->input('preview') ?? false;
        $this->event->eventFeature->budget = $this->request->input('eventBudget') ?? false;
        $this->event->eventFeature->analytics = $this->request->input('analytics') ?? false;
        $this->event->eventFeature->save();
        

        $actor = request()->user();
        if ($actor) {
            $actor->last_active_event_id = $event->id;
            $actor->save();
        }
        
        EventActivityLogger::log(
            $event->id,
            'event_updated',
            $actor ?? null,
            $event,
            [
                'event_name' => $this->event->event_name,
                'event_description' => $this->event->event_description,
                'event_type_id' => $this->event->event_type_id,
                'event_plan_id' => $this->event->event_plan_id,
                'start_date' => $this->event->start_date,
                'end_date' => $this->event->end_date,
                'status' => $this->event->status,
                'custom_url_slug' => $this->event->custom_url_slug,
                'visibility' => $this->event->visibility,
            ]
        );
        
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
    
    /**
     * Retrieve all event types.
     * @return Collection
     */
    public function getEventTypes(): Collection
    {
        return EventType::query()
            ->select('id', 'name', 'slug', 'icon')
            ->get();
    }
    
    /**
     * Retrieve all event plans.
     *
     * @return Collection
     */
    public function getEventPlans(): Collection
    {
        return EventPlan::query()->get();
    }
    
    public function getEventSuggestions(Events $event): array
    {
        $suggestions = [];
        $maxGuests = $event->eventPlan->max_guests;
        $eventGuestsCount = $event->guests()->count();
        
        if ($maxGuests !== 0) {
            $remainingGuests = $maxGuests - $eventGuestsCount;
            if ($eventGuestsCount === 0) {
                $suggestions[] = [
                    'name' => 'Invite Guests',
                    'description' => 'You can invite guests to your event.',
                    'url' => '/dashboard/invite-guests',
                ];
            } elseif ($eventGuestsCount < $maxGuests) {
                $suggestions[] = [
                    'name' => 'Invite Guests',
                    'description' => "You can invite {$remainingGuests} more guests.",
                    'url' => '/dashboard/invite-guests',
                ];
            } else {
                $suggestions[] = [
                    'name' => 'Upgrade Plan',
                    'description' => 'You have reached the maximum number of guests for your current plan.',
                    'url' => '/dashboard/upgrade-plan',
                ];
            }
        }
        
        if ($maxGuests === 0) {
            $suggestions[] = [
                'name' => 'Invite more guests',
                'description' => 'Base on your event plan you can continue inviting. Unlimited Guests',
                'url' => '/dashboard/guests/create',
            ];
        }
        
        
        if (!$event->saveTheDate) {
            $suggestions[] = [
                'name' => 'Save the Date',
                'description' => 'Create a save the date page for your event.',
                'url' => '/dashboard/save-the-date',
            ];
            
        }
        
        // Todo: Work on the rests of suggestions later.
        
        return $suggestions;
    }
    
}
