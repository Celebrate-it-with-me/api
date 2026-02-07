<?php

namespace App\Http\Services\AppServices\Hydrate;

use App\Models\Events;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for hydrating user data with their events and related information.
 */
readonly class HydrationService
{
    /**
     * @param EventRepository $eventRepository
     * @param EventAuthorizationService $authorizationService
     * @param EventDataLoader $dataLoader
     * @param HydrationResponseBuilder $responseBuilder
     */
    public function __construct(
        private EventRepository           $eventRepository,
        private EventAuthorizationService $authorizationService,
        private EventDataLoader           $dataLoader,
        private HydrationResponseBuilder  $responseBuilder
    ) {}

    public function hydrate(User $user): JsonResponse
    {
        $events = $this->eventRepository->getAccessibleEvents($user);
        $activeEvent = $this->eventRepository->getActiveEventWithRelations($user);

        return $this->buildResponse($activeEvent, $events, $user);
    }

    public function hydrateForEvent(User $user, int $eventId): JsonResponse
    {
        $events = $this->eventRepository->getAccessibleEvents($user);
        $event = $this->eventRepository->findWithRelations($eventId, [
            'menus',
            'eventFeature',
            'guests',
            'rsvp',
            'saveTheDate',
            'comments'
        ]);

        return $this->buildResponse($event, $events, $user);
    }

    /**
     * @param Events|null $event
     * @param Collection $events
     * @param User $user
     * @return JsonResponse
     */
    private function buildResponse(?Events $event, Collection $events, User $user): JsonResponse
    {
        if (!$event) {
            return $this->responseBuilder
                ->withEvents($events)
                ->buildEmpty();
        }

        $permissions = $this->authorizationService->validateAndGetPermissions($user, $event);

        if ($permissions === null) {
            return $this->responseBuilder->buildUnauthorized();
        }

        $eventData = $this->dataLoader->loadEventData($event);

        return $this->responseBuilder
            ->withEvents($events)
            ->withActiveEvent($event)
            ->withEventData($eventData)
            ->withUserPermissions($permissions)
            ->build();
    }

}
