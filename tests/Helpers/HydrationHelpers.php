<?php

/**
 * Pest Configuration and Helpers for Hydration Tests
 *
 * Add this to your tests/Pest.php file or create a separate
 * tests/Helpers/HydrationHelpers.php file
 */

use App\Http\Services\AppServices\Hydrate\EventCacheService;
use App\Http\Services\AppServices\Hydrate\EventAuthorizationService;
use App\Http\Services\AppServices\Hydrate\EventDataLoader;
use App\Http\Services\AppServices\Hydrate\EventRepository;
use App\Http\Services\AppServices\Hydrate\HydrationResponseBuilder;
use App\Http\Services\AppServices\Hydrate\HydrationService;
use App\Models\Events;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Test Case Configuration
|--------------------------------------------------------------------------
|
| Configure which test case to use for different test files
|
*/

uses(Tests\TestCase::class)->in('Unit/Services/Hydrate');

/*
|--------------------------------------------------------------------------
| Custom Expectations
|--------------------------------------------------------------------------
|
| Add custom expectations for hydration testing
|
*/

expect()->extend('toBeValidHydrationResponse', function () {
    $data = $this->value->getData(true);

    expect($data)->toHaveKeys([
        'events',
        'activeEvent',
        'menus',
        'eventFeatures',
        'guests',
        'rsvp',
        'saveTheDate',
        'menuGuests',
        'locations',
        'userPermissions',
        'eventTypes',
        'eventPlans',
    ]);

    return $this;
});

expect()->extend('toBeEmptyHydrationResponse', function () {
    $data = $this->value->getData(true);

    expect($data['activeEvent'])->toBeNull()
        ->and($data['menus'])->toBeNull()
        ->and($data['guests'])->toBeNull()
        ->and($data['rsvp'])->toBeNull();

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
|
| Reusable helper functions for hydration tests
|
*/

/**
 * Create a mock HydrationService with customizable behavior
 *
 * @param array $config Configuration for mocked dependencies
 * @return HydrationService
 */
function createMockHydrationService(array $config = []): HydrationService
{
    $eventRepository = $config['eventRepository'] ?? Mockery::mock(EventRepository::class);
    $authService = $config['authService'] ?? Mockery::mock(EventAuthorizationService::class);
    $dataLoader = $config['dataLoader'] ?? Mockery::mock(EventDataLoader::class);

    $cacheService = Mockery::mock(EventCacheService::class);
    $cacheService->shouldReceive('getEventTypes')->andReturn(collect());
    $cacheService->shouldReceive('getEventPlans')->andReturn(collect());

    $responseBuilder = new HydrationResponseBuilder($cacheService);

    return new HydrationService(
        $eventRepository,
        $authService,
        $dataLoader,
        $responseBuilder
    );
}

/**
 * Create a user with an active event
 *
 * @return array{user: User, event: Events}
 */
function createUserWithEvent(): array
{
    $event = Events::factory()->create();
    $user = User::factory()->create([
        'last_active_event_id' => $event->id
    ]);

    return [
        'user' => $user,
        'event' => $event
    ];
}

/**
 * Create mock event repository with standard responses
 *
 * @param User|null $user
 * @param Events|null $event
 * @return EventRepository
 */
function mockEventRepository(?User $user = null, ?Events $event = null): EventRepository
{
    $repository = Mockery::mock(EventRepository::class);

    $repository->shouldReceive('getAccessibleEvents')
        ->andReturn($event ? collect([$event]) : collect());

    $repository->shouldReceive('getActiveEventWithRelations')
        ->andReturn($event);

    return $repository;
}

/**
 * Create mock authorization service
 *
 * @param bool $authorized
 * @param array $permissions
 * @return EventAuthorizationService
 */
function mockAuthService(bool $authorized = true, array $permissions = ['view_event']): EventAuthorizationService
{
    $service = Mockery::mock(EventAuthorizationService::class);

    $service->shouldReceive('validateAndGetPermissions')
        ->andReturn($authorized ? $permissions : null);

    return $service;
}

/**
 * Create mock data loader with default empty data
 *
 * @param array $customData
 * @return EventDataLoader
 */
function mockDataLoader(array $customData = []): EventDataLoader
{
    $defaultData = [
        'menus' => null,
        'eventFeatures' => null,
        'guests' => [],
        'rsvp' => [],
        'saveTheDate' => null,
        'menuGuests' => [],
        'locations' => [],
    ];

    $data = array_merge($defaultData, $customData);

    $loader = Mockery::mock(EventDataLoader::class);
    $loader->shouldReceive('loadEventData')->andReturn($data);

    return $loader;
}

/**
 * Assert response has valid hydration structure
 *
 * @param \Illuminate\Http\JsonResponse $response
 * @return void
 */
function assertValidHydrationStructure($response): void
{
    expect($response->getStatusCode())->toBe(200);

    $data = $response->getData(true);

    expect($data)->toHaveKeys([
        'events',
        'activeEvent',
        'menus',
        'eventFeatures',
        'guests',
        'rsvp',
        'saveTheDate',
        'menuGuests',
        'locations',
        'userPermissions',
        'eventTypes',
        'eventPlans',
    ]);
}

/*
|--------------------------------------------------------------------------
| Dataset Definitions
|--------------------------------------------------------------------------
|
| Define reusable datasets for testing different scenarios
|
*/

dataset('user_scenarios', [
    'user with no events' => [
        fn() => User::factory()->create(['last_active_event_id' => null])
    ],
    'user with active event' => [
        fn() => User::factory()->create([
            'last_active_event_id' => Events::factory()->create()->id
        ])
    ],
]);

dataset('permission_scenarios', [
    'view only' => [['view_event']],
    'view and edit' => [['view_event', 'edit_event']],
    'full permissions' => [['view_event', 'edit_event', 'delete_event', 'manage_guests']],
    'no permissions' => [null],
]);

dataset('event_data_scenarios', [
    'empty event' => [[]],
    'event with menus' => [[
        'menus' => collect(['menu_1', 'menu_2']),
    ]],
    'event with guests' => [[
        'guests' => [
            ['id' => 1, 'name' => 'Guest 1'],
            ['id' => 2, 'name' => 'Guest 2'],
        ],
    ]],
    'complete event' => [[
        'menus' => collect(['menu_1']),
        'guests' => [['id' => 1, 'name' => 'Guest 1']],
        'rsvp' => [['id' => 1, 'status' => 'confirmed']],
        'locations' => [['id' => 1, 'name' => 'Venue']],
    ]],
]);

/*
|--------------------------------------------------------------------------
| Hooks
|--------------------------------------------------------------------------
|
| Global before/after hooks for hydration tests
|
*/

// Global setup for all hydration tests
function setupHydrationTest(): void
{
    // Clear cache before each test
    Cache::flush();

    // Seed any necessary data
    // DB::seed(EventTypeSeeder::class);
    // DB::seed(EventPlanSeeder::class);
}

// Global cleanup
function cleanupHydrationTest(): void
{
    Mockery::close();
    Cache::flush();
}
