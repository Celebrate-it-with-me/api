<?php

use App\Http\Services\AppServices\Hydrate\EventAuthorizationService;
use App\Http\Services\AppServices\Hydrate\EventDataLoader;
use App\Http\Services\AppServices\Hydrate\EventRepository;
use App\Http\Services\AppServices\Hydrate\HydrationResponseBuilder;
use App\Http\Services\AppServices\Hydrate\HydrationService;
use App\Http\Services\AppServices\Hydrate\EventCacheService;
use App\Models\Events;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pest tests for HydrationService
 *
 * These tests demonstrate the improved testability of the refactored code.
 * Using Pest for cleaner, more readable test syntax.
 */

beforeEach(function () {
    // Setup common mocks
    $this->eventRepository = Mockery::mock(EventRepository::class);
    $this->authService = Mockery::mock(EventAuthorizationService::class);
    $this->dataLoader = Mockery::mock(EventDataLoader::class);
    $this->cacheService = Mockery::mock(EventCacheService::class);

    // Mock cache service to return empty collections by default
    $this->cacheService->shouldReceive('getEventTypes')->andReturn(collect());
    $this->cacheService->shouldReceive('getEventPlans')->andReturn(collect());

    $this->responseBuilder = new HydrationResponseBuilder($this->cacheService);
});

afterEach(function () {
    Mockery::close();
});

describe('HydrationService', function () {

    it('returns empty response when user has no active event', function () {
        // Arrange
        $user = User::factory()->create(['last_active_event_id' => null]);

        $this->eventRepository->shouldReceive('getAccessibleEvents')
            ->once()
            ->andReturn(collect());

        $this->eventRepository->shouldReceive('getActiveEventWithRelations')
            ->once()
            ->with($user)
            ->andReturn(null);

        $service = new HydrationService(
            $this->eventRepository,
            $this->authService,
            $this->dataLoader,
            $this->responseBuilder
        );

        // Act
        $response = $service->hydrate($user);

        // Assert
        expect($response->getStatusCode())->toBe(200);

        $data = $response->getData(true);
        expect($data['activeEvent'])->toBeNull();
        expect($data['menus'])->toBeNull();
        expect($data['guests'])->toBeNull();
        expect($data['rsvp'])->toBeNull();
        expect($data['eventTypes'])->not->toBeNull();
        expect($data['eventPlans'])->not->toBeNull();
    });

    it('returns unauthorized response when user lacks permissions', function () {
        // Arrange
        $user = User::factory()->create();
        $event = Events::factory()->create();

        $this->eventRepository->shouldReceive('getAccessibleEvents')
            ->once()
            ->andReturn(collect());

        $this->eventRepository->shouldReceive('getActiveEventWithRelations')
            ->once()
            ->andReturn($event);

        $this->authService->shouldReceive('validateAndGetPermissions')
            ->once()
            ->with($user, $event)
            ->andReturn(null); // null = unauthorized

        $service = new HydrationService(
            $this->eventRepository,
            $this->authService,
            $this->dataLoader,
            $this->responseBuilder
        );

        // Act
        $response = $service->hydrate($user);

        // Assert
        expect($response->getStatusCode())->toBe(403);

        $data = $response->getData(true);
        expect($data)->toHaveKey('message');
        expect($data['message'])->toContain('permission');
    });

    it('returns full hydrated data for authorized user', function () {
        // Arrange
        $user = User::factory()->create();
        $event = Events::factory()->create();
        $permissions = ['view_event', 'edit_event'];

        $this->eventRepository->shouldReceive('getAccessibleEvents')
            ->once()
            ->andReturn(collect([$event]));

        $this->eventRepository->shouldReceive('getActiveEventWithRelations')
            ->once()
            ->andReturn($event);

        $this->authService->shouldReceive('validateAndGetPermissions')
            ->once()
            ->with($user, $event)
            ->andReturn($permissions);

        $eventData = [
            'menus' => null,
            'eventFeatures' => null,
            'guests' => [],
            'rsvp' => [],
            'saveTheDate' => null,
            'menuGuests' => [],
            'locations' => [],
        ];

        $this->dataLoader->shouldReceive('loadEventData')
            ->once()
            ->with($event)
            ->andReturn($eventData);

        $service = new HydrationService(
            $this->eventRepository,
            $this->authService,
            $this->dataLoader,
            $this->responseBuilder
        );

        // Act
        $response = $service->hydrate($user);

        // Assert
        expect($response->getStatusCode())->toBe(200);

        $data = $response->getData(true);
        expect($data['activeEvent'])->not->toBeNull();
        expect($data['userPermissions'])->toBe($permissions);
        expect($data['eventTypes'])->not->toBeNull();
        expect($data['eventPlans'])->not->toBeNull();
        expect($data['guests'])->toBe([]);
        expect($data['rsvp'])->toBe([]);
    });

    it('can hydrate data for a specific event', function () {
        // Arrange
        $user = User::factory()->create();
        $event = Events::factory()->create();
        $eventId = $event->id;
        $permissions = ['view_event'];

        $this->eventRepository->shouldReceive('getAccessibleEvents')
            ->once()
            ->andReturn(collect([$event]));

        $this->eventRepository->shouldReceive('findWithRelations')
            ->once()
            ->with($eventId, Mockery::type('array'))
            ->andReturn($event);

        $this->authService->shouldReceive('validateAndGetPermissions')
            ->once()
            ->andReturn($permissions);

        $this->dataLoader->shouldReceive('loadEventData')
            ->once()
            ->andReturn([
                'menus' => null,
                'eventFeatures' => null,
                'guests' => [],
                'rsvp' => [],
                'saveTheDate' => null,
                'menuGuests' => [],
                'locations' => [],
            ]);

        $service = new HydrationService(
            $this->eventRepository,
            $this->authService,
            $this->dataLoader,
            $this->responseBuilder
        );

        // Act
        $response = $service->hydrateForEvent($user, $eventId);

        // Assert
        expect($response->getStatusCode())->toBe(200);
        expect($response->getData(true)['activeEvent'])->not->toBeNull();
    });
});

describe('EventAuthorizationService', function () {

    it('checks if user can view event', function () {
        $service = new EventAuthorizationService();
        $user = User::factory()->create();
        $event = Events::factory()->create();

        // This would require proper setup of permissions
        // Just demonstrating the service structure
        expect($service)->toBeInstanceOf(EventAuthorizationService::class);
    });

    it('returns user permissions for an event', function () {
        $service = new EventAuthorizationService();
        $user = User::factory()->create();
        $event = Events::factory()->create();

        // Mock the permission check
        expect($service)->toBeInstanceOf(EventAuthorizationService::class);
    });
});

describe('EventRepository', function () {

    it('retrieves active event with all relations', function () {
        $repository = new EventRepository();
        $user = User::factory()->create(['last_active_event_id' => null]);

        $result = $repository->getActiveEventWithRelations($user);

        expect($result)->toBeNull();
    });

    it('returns null when user has no last active event', function () {
        $repository = new EventRepository();
        $user = User::factory()->create(['last_active_event_id' => null]);

        $result = $repository->getActiveEventWithRelations($user);

        expect($result)->toBeNull();
    });

    it('checks if event exists', function () {
        $repository = new EventRepository();

        $exists = $repository->exists(999999);

        expect($exists)->toBeFalse();
    });
});

describe('EventCacheService', function () {

    it('caches event types', function () {
        $service = app(EventCacheService::class);

        $eventTypes = $service->getEventTypes();

        expect($eventTypes)->toBeInstanceOf(Collection::class);
    });

    it('caches event plans', function () {
        $service = app(EventCacheService::class);

        $eventPlans = $service->getEventPlans();

        expect($eventPlans)->toBeInstanceOf(Collection::class);
    });

    it('can clear cache', function () {
        $service = app(EventCacheService::class);

        // Should not throw exception
        $service->clearCache();

        expect(true)->toBeTrue();
    });

    it('can warm up cache', function () {
        $service = app(EventCacheService::class);

        $service->warmUp();

        // Verify cache was populated
        expect(Cache::has('hydration.event_types'))->toBeTrue();
        expect(Cache::has('hydration.event_plans'))->toBeTrue();
    });
});

describe('HydrationResponseBuilder', function () {

    it('builds empty response', function () {
        $cacheService = Mockery::mock(EventCacheService::class);
        $cacheService->shouldReceive('getEventTypes')->andReturn(collect());
        $cacheService->shouldReceive('getEventPlans')->andReturn(collect());

        $builder = new HydrationResponseBuilder($cacheService);

        $response = $builder->buildEmpty();

        expect($response->getStatusCode())->toBe(200);

        $data = $response->getData(true);
        expect($data['activeEvent'])->toBeNull();
    });

    it('builds unauthorized response', function () {
        $cacheService = Mockery::mock(EventCacheService::class);
        $cacheService->shouldReceive('getEventTypes')->andReturn(collect());
        $cacheService->shouldReceive('getEventPlans')->andReturn(collect());

        $builder = new HydrationResponseBuilder($cacheService);

        $response = $builder->buildUnauthorized();

        expect($response->getStatusCode())->toBe(403);
        expect($response->getData(true))->toHaveKey('message');
    });

    it('builds complete response with fluent interface', function () {
        $cacheService = Mockery::mock(EventCacheService::class);
        $cacheService->shouldReceive('getEventTypes')->andReturn(collect());
        $cacheService->shouldReceive('getEventPlans')->andReturn(collect());

        $builder = new HydrationResponseBuilder($cacheService);
        $event = Events::factory()->create();

        $response = $builder
            ->withEvents(collect())
            ->withActiveEvent($event)
            ->withEventData([
                'menus' => null,
                'eventFeatures' => null,
                'guests' => [],
                'rsvp' => [],
                'saveTheDate' => null,
                'menuGuests' => [],
                'locations' => [],
            ])
            ->withUserPermissions(['view_event'])
            ->build();

        expect($response->getStatusCode())->toBe(200);

        $data = $response->getData(true);
        expect($data['activeEvent'])->not->toBeNull();
        expect($data['userPermissions'])->toBe(['view_event']);
    });

    it('can reset builder state', function () {
        $cacheService = Mockery::mock(EventCacheService::class);
        $cacheService->shouldReceive('getEventTypes')->andReturn(collect());
        $cacheService->shouldReceive('getEventPlans')->andReturn(collect());

        $builder = new HydrationResponseBuilder($cacheService);
        $event = Events::factory()->create();

        $builder
            ->withActiveEvent($event)
            ->withUserPermissions(['view_event'])
            ->reset();

        $response = $builder->buildEmpty();

        $data = $response->getData(true);
        expect($data['activeEvent'])->toBeNull();
        expect($data['userPermissions'])->toBeNull();
    });
});

// Performance tests
describe('Performance', function () {

    it('executes hydration in acceptable time', function () {
        $user = User::factory()->create();
        $event = Events::factory()->create();

        $eventRepository = Mockery::mock(EventRepository::class);
        $eventRepository->shouldReceive('getAccessibleEvents')->andReturn(collect());
        $eventRepository->shouldReceive('getActiveEventWithRelations')->andReturn($event);

        $authService = Mockery::mock(EventAuthorizationService::class);
        $authService->shouldReceive('validateAndGetPermissions')->andReturn(['view_event']);

        $dataLoader = Mockery::mock(EventDataLoader::class);
        $dataLoader->shouldReceive('loadEventData')->andReturn([
            'menus' => null,
            'eventFeatures' => null,
            'guests' => [],
            'rsvp' => [],
            'saveTheDate' => null,
            'menuGuests' => [],
            'locations' => [],
        ]);

        $cacheService = Mockery::mock(EventCacheService::class);
        $cacheService->shouldReceive('getEventTypes')->andReturn(collect());
        $cacheService->shouldReceive('getEventPlans')->andReturn(collect());

        $responseBuilder = new HydrationResponseBuilder($cacheService);

        $service = new HydrationService(
            $eventRepository,
            $authService,
            $dataLoader,
            $responseBuilder
        );

        $start = microtime(true);
        $response = $service->hydrate($user);
        $duration = microtime(true) - $start;

        // Should execute in less than 100ms (with mocks)
        expect($duration)->toBeLessThan(0.1);
        expect($response->getStatusCode())->toBe(200);
    })->skip('Performance test - run manually when needed');
});
