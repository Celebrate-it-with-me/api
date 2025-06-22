<?php

namespace App\Http\Services\AppServices;

use App\Http\Services\Logger\EventActivityLogger;
use App\Models\EventFeature;
use App\Models\Events;
use App\Models\EventUserRole;
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
        $this->event = new Events;
    }

    /**
     * Get user logged events.
     *
     * @return Collection
     *
     * @throws Exception
     */
    public function getUserEvents(): array
    {
        $user = $this->request->user();

        if (! $user) {
            throw new Exception('User not authenticated');
        }

        if ($user->last_active_event_id) {
            $lastActiveEvent = Events::query()
                ->with(['userRoles.user', 'userRoles.role'])
                ->where('id', $user->last_active_event_id)
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
     */
    public function getFilteredEvents(string $query): Collection
    {
        return Events::query()
            ->where('organizer_id', $this->request->user()->id)
            ->when($query, function ($subQuery) use ($query) {
                $subQuery->whereNested(function ($subQuery) use ($query) {
                    $subQuery->where('event_name', 'like', '%' . $query . '%');
                    $subQuery->orWhere('event_description', 'like', '%' . $query . '%');
                });
            })
            ->get();
    }

    /**
     * Create user event.
     *
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

        if (! $event) {
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
            'role_id' => Role::query()->where('name', 'owner')->first()->id,
        ]);

        return $event;
    }

    /**
     * Update user event info.
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
        $this->event->eventFeature->budget = $this->request->input('budget') ?? false;
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
     * Get event suggestions based on the current event state.
     */
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
        if (! $event->saveTheDate) {
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
        if ($event->eventFeature->rsvp && ! $event->rsvp) {
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
        if ($event->eventFeature->background_music && ! $event->backgroundMusic) {
            $suggestions[] = [
                'name' => 'Set Up Background Music',
                'description' => 'Add background music to your event page.',
                'url' => '/dashboard/background-music/setup',
            ];
        }

        // Sweet Memories suggestions
        if ($event->eventFeature->sweet_memories) {
            $sweetMemoriesImagesCount = $event->sweetMemoriesImages()->count();
            if (! $event->sweetMemoriesConfig) {
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
        if ($event->eventFeature->event_comments && ! $event->eventConfigComment) {
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
