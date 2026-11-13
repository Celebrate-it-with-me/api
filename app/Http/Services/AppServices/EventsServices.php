<?php

namespace App\Http\Services\AppServices;

use App\Enums\EventStatus;
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
    protected Events $event;

    public function __construct()
    {
        $this->event = new Events();
    }

    /**
     * The request being handled right now. It is read on every use, never kept: a service that holds
     * the request it was built with keeps answering the first request when the app handles several.
     */
    protected function request(): Request
    {
        return app('request');
    }

    /**
     * Get user logged events.
     * @return Collection
     */
    public function getUserEvents(): array
    {
        $user = $this->request()->user();

        if (!$user) {
            throw new Exception('User not authenticated');
        }

        if ($user->last_active_event_id) {
            $lastActiveEvent = Events::query()
                ->with(['userRoles.user', 'userRoles.role'])
                ->where('id', $user->last_active_event_id)
                ->whereHas('userRoles', fn ($query) => $query->where('user_id', $user->id))
                ->first();
        }

        $events = Events::query()
            ->with(['userRoles.user', 'userRoles.role'])
            ->whereHas('userRoles', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->get();

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
            ->whereHas('userRoles', fn ($roles) => $roles->where('user_id', $this->request()->user()->id))
            ->when($query, function ($subQuery) use ($query) {
                $subQuery->whereNested(function($subQuery) use ($query) {
                   $subQuery->where('event_name', 'like', '%' . $query . '%');
                   $subQuery->orWhere('event_description', 'like', '%' . $query . '%');
                });
            })
            ->get();
    }

    /**
     * Create user event. It always starts as a draft: publishing is its own action.
     * @return Model|Builder
     * @throws Exception
     */
    public function create(): Model|Builder
    {
        $event = Events::query()->create([
            'event_name' => $this->request()->input('eventName'),
            'event_description' => $this->request()->input('eventDescription'),
            'event_type_id' => $this->request()->input('eventType'),
            // The plan is decided by the server (free by default), never by the client.
            'event_plan_id' => $this->defaultPlanId(),
            'start_date' => $this->parseDate($this->request()->input('startDate')),
            'end_date' => $this->parseDate($this->request()->input('endDate')),
            'status' => EventStatus::DRAFT->value,
            'custom_url_slug' => $this->request()->input('customUrlSlug')
                ?: $this->uniqueSlug((string) $this->request()->input('eventName')),
        ]);

        // A new event starts with the core only; the premium modules are not switched on here, the plan
        // decides them (see EventModules). These flags are legacy: the menu no longer reads them.
        EventFeature::query()->create([
            'event_id' => $event->id,
            'save_the_date' => true,
            'rsvp' => true,
            'location' => true,
            'budget' => true,
            'menu' => false,
            'sweet_memories' => false,
            'music' => false,
            'background_music' => false,
            'event_comments' => false,
            'seats_accommodation' => false,
            'preview' => false,
            'analytics' => false,
        ]);

        $actor = $this->request()->user();

        if ($actor) {
            $actor->last_active_event_id = $event->id;
            $actor->save();
        }

        EventActivityLogger::log($event->id, 'event_created', $actor, $event, $this->activityPayload($event));

        EventUserRole::query()->firstOrCreate([
            'event_id' => $event->id,
            'user_id' => $actor->id,
        ], [
            'role_id' => Role::query()->where('name', 'owner')->first()->id,
        ]);

        // The owner role was just created: load it, or the answer says the creator has no role and the app hides
        // every edit button on a brand-new event.
        return $event->load('userRoles.role');
    }

    /**
     * Update the event. Only what the request carries is touched, so a form that sends just the
     * details never switches features off. Status and plan are not editable here.
     */
    public function update(Events $event): Events
    {
        $this->event = $event;
        $input = $this->request();

        foreach (['eventName' => 'event_name', 'eventDescription' => 'event_description', 'eventType' => 'event_type_id'] as $key => $column) {
            if ($input->has($key)) {
                $event->{$column} = $input->input($key);
            }
        }

        // The form request already rejected a different address once the event has been published.
        if ($input->has('customUrlSlug') && $input->input('customUrlSlug')) {
            $event->custom_url_slug = $input->input('customUrlSlug');
        }
        if ($input->has('startDate')) {
            $event->start_date = $this->parseDate($input->input('startDate'));
        }
        if ($input->has('endDate')) {
            $event->end_date = $this->parseDate($input->input('endDate'));
        }
        $event->save();

        $actor = $this->request()->user();
        if ($actor) {
            $actor->last_active_event_id = $event->id;
            $actor->save();
        }

        EventActivityLogger::log($event->id, 'event_updated', $actor, $event, $this->activityPayload($event));

        return $event->refresh();
    }

    /** Dates travel as `m/d/Y H:i`; an empty value means "no date". */
    private function parseDate(?string $value): ?Carbon
    {
        return $value ? Carbon::createFromFormat('m/d/Y H:i', $value) : null;
    }

    /** A readable slug that is free right now: `los-xv-de-valentina`, then `-2`, `-3`... or a short suffix. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'evento', 50, '');
        $slug = $base;
        $attempt = 1;

        // withTrashed: the unique index also counts events that were soft deleted.
        while (Events::withTrashed()->where('custom_url_slug', $slug)->exists()) {
            $attempt++;
            $slug = $attempt <= 5 ? "{$base}-{$attempt}" : $base . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }

    private function activityPayload(Events $event): array
    {
        return [
            'event_name' => $event->event_name,
            'event_description' => $event->event_description,
            'event_type_id' => $event->event_type_id,
            'event_plan_id' => $event->event_plan_id,
            'start_date' => $event->start_date,
            'end_date' => $event->end_date,
            'status' => $event->status,
            'custom_url_slug' => $event->custom_url_slug,
        ];
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
    /** The entry plan: the one with the lowest id (`basic` in the seeders). */
    private function defaultPlanId(): int
    {
        return (int) EventPlan::query()->orderBy('id')->value('id');
    }

    public function getEventTypes(): Collection
    {
        return EventType::query()
            ->active()
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

        // Guest suggestions based on plan limits
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

        // Save the Date suggestion
        if (!$event->saveTheDate) {
            $suggestions[] = [
                'name' => 'Save the Date',
                'description' => 'Create a save the date page for your event.',
                'url' => '/dashboard/save-the-date',
            ];
        }

        // Location suggestions
        if ($event->eventFeature->location) {
            $locationsCount = $event->locations()->count();
            if ($locationsCount === 0) {
                $suggestions[] = [
                    'name' => 'Add Location',
                    'description' => 'Add a location for your event.',
                    'url' => '/dashboard/locations/create',
                ];
            }
        }

        // Menu suggestions
        if ($event->eventFeature->menu) {
            $menusCount = $event->menus()->count();
            if ($menusCount === 0) {
                $suggestions[] = [
                    'name' => 'Create Menu',
                    'description' => 'Create a menu for your event.',
                    'url' => '/dashboard/menus/create',
                ];
            }
        }

        // RSVP suggestions
        if ($event->eventFeature->rsvp && !$event->rsvp) {
            $suggestions[] = [
                'name' => 'Set Up RSVP',
                'description' => 'Configure RSVP settings for your event.',
                'url' => '/dashboard/rsvp/setup',
            ];
        }

        // Music suggestions
        if ($event->eventFeature->music) {
            $musicSuggestionsCount = $event->musicSuggestions()->count();
            if ($musicSuggestionsCount === 0) {
                $suggestions[] = [
                    'name' => 'Add Music Suggestions',
                    'description' => 'Allow guests to suggest music for your event.',
                    'url' => '/dashboard/music/setup',
                ];
            }
        }

        // Background Music suggestions
        if ($event->eventFeature->background_music && !$event->backgroundMusic) {
            $suggestions[] = [
                'name' => 'Set Up Background Music',
                'description' => 'Add background music to your event page.',
                'url' => '/dashboard/background-music/setup',
            ];
        }

        // Sweet Memories suggestions
        if ($event->eventFeature->sweet_memories) {
            $sweetMemoriesImagesCount = $event->sweetMemoriesImages()->count();
            if (!$event->sweetMemoriesConfig) {
                $suggestions[] = [
                    'name' => 'Configure Sweet Memories',
                    'description' => 'Set up the Sweet Memories feature for your event.',
                    'url' => '/dashboard/sweet-memories/setup',
                ];
            } elseif ($sweetMemoriesImagesCount === 0) {
                $suggestions[] = [
                    'name' => 'Add Sweet Memories Images',
                    'description' => 'Upload images to your Sweet Memories gallery.',
                    'url' => '/dashboard/sweet-memories/images/upload',
                ];
            }
        }

        // Event Comments suggestions
        if ($event->eventFeature->event_comments && !$event->eventConfigComment) {
            $suggestions[] = [
                'name' => 'Configure Event Comments',
                'description' => 'Set up the comments feature for your event.',
                'url' => '/dashboard/comments/setup',
            ];
        }

        // Seats Accommodation suggestions
        if ($event->eventFeature->seats_accommodation) {
            // Check if guests exist before suggesting seating arrangement
            if ($eventGuestsCount > 0) {
                $suggestions[] = [
                    'name' => 'Set Up Seating Arrangement',
                    'description' => 'Create a seating arrangement for your event.',
                    'url' => '/dashboard/seating/setup',
                ];
            } else {
                $suggestions[] = [
                    'name' => 'Invite Guests First',
                    'description' => 'Invite guests before setting up seating arrangements.',
                    'url' => '/dashboard/invite-guests',
                ];
            }
        }

        // Budget suggestions
        if ($event->eventFeature->budget) {
            $suggestions[] = [
                'name' => 'Set Up Budget',
                'description' => 'Create a budget for your event.',
                'url' => '/dashboard/budget/setup',
            ];
        }

        // Analytics suggestions
        if ($event->eventFeature->analytics) {
            $suggestions[] = [
                'name' => 'View Analytics',
                'description' => 'Check the analytics for your event.',
                'url' => '/dashboard/analytics',
            ];
        }

        // Event status-based suggestions
        if ($event->status === 'draft') {
            $suggestions[] = [
                'name' => 'Publish Event',
                'description' => 'Your event is currently in draft mode. Publish it to make it visible to guests.',
                'url' => '/dashboard/publish',
            ];
        } elseif ($event->status === 'published') {
            $suggestions[] = [
                'name' => 'Share Event',
                'description' => 'Your event is published. Share it with your guests.',
                'url' => '/dashboard/share',
            ];
        }

        // Collaborators suggestions
        $collaboratorsCount = $event->collaborators()->count();
        if ($collaboratorsCount === 0) {
            $suggestions[] = [
                'name' => 'Invite Collaborators',
                'description' => 'Invite others to help you plan your event.',
                'url' => '/dashboard/collaborators/invite',
            ];
        }

        // Event date approaching suggestion
        $now = Carbon::now();
        $eventDate = Carbon::parse($event->start_date);
        $daysUntilEvent = $now->diffInDays($eventDate, false);

        if ($daysUntilEvent > 0 && $daysUntilEvent <= 7) {
            $suggestions[] = [
                'name' => 'Event Approaching',
                'description' => "Your event is coming up in {$daysUntilEvent} days. Make sure everything is ready!",
                'url' => '/dashboard/event-checklist',
            ];
        } elseif ($daysUntilEvent < 0) {
            // Event has passed
            $daysAgo = abs($daysUntilEvent);
            $suggestions[] = [
                'name' => 'Event Completed',
                'description' => "Your event took place {$daysAgo} days ago. Don't forget to thank your guests!",
                'url' => '/dashboard/thank-you-notes',
            ];
        }

        // Prioritize suggestions
        // First, event date related suggestions (most time-sensitive)
        // Then, status-related suggestions
        // Then, guest-related suggestions
        // Then, feature-related suggestions

        $prioritizedSuggestions = [];
        $dateRelatedSuggestions = [];
        $statusRelatedSuggestions = [];
        $guestRelatedSuggestions = [];
        $featureRelatedSuggestions = [];

        foreach ($suggestions as $suggestion) {
            if (in_array($suggestion['name'], ['Event Approaching', 'Event Completed'])) {
                $dateRelatedSuggestions[] = $suggestion;
            } elseif (in_array($suggestion['name'], ['Publish Event', 'Share Event'])) {
                $statusRelatedSuggestions[] = $suggestion;
            } elseif (in_array($suggestion['name'], ['Invite Guests', 'Invite more guests', 'Upgrade Plan', 'Invite Guests First'])) {
                $guestRelatedSuggestions[] = $suggestion;
            } else {
                $featureRelatedSuggestions[] = $suggestion;
            }
        }

        return array_merge($dateRelatedSuggestions, $statusRelatedSuggestions, $guestRelatedSuggestions, $featureRelatedSuggestions);
    }

}
