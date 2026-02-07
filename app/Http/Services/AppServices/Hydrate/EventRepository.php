<?php

namespace App\Http\Services\AppServices\Hydrate;

use App\Models\Events;
use App\Models\User;
use Illuminate\Support\Collection;

class EventRepository
{
    /**
     * Retrieves the active event associated with the given user, along with its related data.
     *
     * @param User $user The user whose active event needs to be retrieved.
     * @return Events|null Returns the active event with its related data, or null if no active event exists for the user.
     */
    public function getActiveEventWithRelations(User $user): ?Events
    {
        if (!$user->last_active_event_id) {
            return null;
        }

        return Events::query()
            ->with([
                'menus',
                'eventFeature',
                'guests',
                'rsvp',
                'saveTheDate',
                'comments'
            ])->where('id', $user->last_active_event_id)
            ->first();
    }

    /**
     * Retrieves a collection of events that the given user has access to.
     *
     * @param User $user The user for whom the accessible events are being retrieved.
     * @return Collection Returns a collection of events that the user can access.
     */
    public function getAccessibleEvents(User $user): Collection
    {
        return $user->accessibleEvents();
    }

    /**
     * Checks whether an event with the specified ID exists in the database.
     *
     * @param int $eventId The unique identifier of the event to check for existence.
     * @return bool Returns true if the event exists, otherwise false.
     */
    public function exists(int $eventId): bool
    {
        return Events::query()->where('id', $eventId)->exists();
    }

    /**
     * Retrieves an event with the specified ID, along with optional related data.
     *
     * @param int $eventId The unique identifier of the event to retrieve.
     * @param array $relations Optional array of relations to eager load with the event.
     * @return Events|null Returns the event with the specified ID and related data, or null if not found.
     */
    public function findWithRelations(int $eventId, array $relations = []): ?Events
    {
        return Events::query()->with($relations)->find($eventId);
    }
}
