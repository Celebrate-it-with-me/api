<?php

use App\Http\Services\AppServices\EventModules;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Module;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function eventOnPlan(string $planSlug): array
{
    $owner = User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $planSlug)->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner];
}

function moduleStates(array $modules): array
{
    return collect($modules)->pluck('state', 'key')->all();
}

describe('the catalog', function () {
    it('is seeded in menu order, with the modules that are not built yet switched off', function () {
        $keys = Module::query()->enabled()->get()->sortBy(['section', 'sort_order'])->pluck('key');

        expect(Module::count())->toBe(14)
            ->and(Module::where('key', 'invitation')->value('is_enabled'))->toBeFalse()
            ->and(Module::where('key', 'padrinos')->value('is_enabled'))->toBeTrue()
            ->and($keys)->not->toContain('invitation')->toContain('padrinos');
    });

    it('never overwrites what the admin changed when it is seeded again', function () {
        Module::where('key', 'budget')->update(['is_enabled' => false, 'sort_order' => 99]);
        Module::where('key', 'menus')->first()->plans()->detach();

        $this->seed(ModuleSeeder::class);

        expect(Module::where('key', 'budget')->first())->is_enabled->toBeFalse()->sort_order->toBe(99)
            ->and(Module::where('key', 'menus')->first()->plans()->count())->toBe(0);
    });
});

describe('what an event shows', function () {
    it('on the entry plan: the core active, the premium modules locked, unbuilt ones absent', function () {
        [$event] = eventOnPlan('basic');

        $states = moduleStates(app(EventModules::class)->forEvent($event));

        expect($states)->toMatchArray([
            'guests' => 'active', 'rsvp' => 'active', 'save_the_date' => 'active',
            'timeline' => 'active', 'locations' => 'active', 'dress_code' => 'active', 'budget' => 'active', 'padrinos' => 'active',
            'menus' => 'locked', 'seating' => 'locked', 'suggested_music' => 'locked', 'memories' => 'locked', 'comments' => 'locked',
        ])->and($states)->not->toHaveKeys(['invitation']);
    });

    it('on a paid plan: everything enabled is active', function () {
        [$event] = eventOnPlan('premium');

        expect(collect(moduleStates(app(EventModules::class)->forEvent($event)))->unique()->values()->all())->toBe(['active']);
    });

    it('comes in menu order: by section, then by the module order', function () {
        [$event] = eventOnPlan('basic');

        expect(collect(app(EventModules::class)->forEvent($event))->pluck('key')->all())->toBe([
            'guests', 'rsvp', 'save_the_date',
            'timeline', 'locations', 'dress_code', 'budget', 'padrinos',
            'menus', 'seating', 'suggested_music', 'memories', 'comments',
        ]);
    });

    it('follows the admin: a disabled module disappears, a module added to a plan becomes active', function () {
        [$event] = eventOnPlan('basic');

        Module::where('key', 'dress_code')->update(['is_enabled' => false]);
        Module::where('key', 'invitation')->update(['is_enabled' => true]);             // built: switch on
        Module::where('key', 'menus')->first()->plans()->attach($event->event_plan_id); // give it to this plan

        $states = moduleStates(app(EventModules::class)->forEvent($event));

        expect($states)->not->toHaveKey('dress_code')
            ->and($states['invitation'])->toBe('active')
            ->and($states['menus'])->toBe('active')
            ->and($states['seating'])->toBe('locked');
    });

    it('answers stateOf and isActive for one module', function () {
        [$event] = eventOnPlan('basic');
        $modules = app(EventModules::class);

        expect($modules->stateOf($event, 'rsvp'))->toBe('active')
            ->and($modules->stateOf($event, 'menus'))->toBe('locked')
            ->and($modules->stateOf($event, 'padrinos'))->toBe('active')
            ->and($modules->stateOf($event, 'invitation'))->toBeNull()
            ->and($modules->stateOf($event, 'does_not_exist'))->toBeNull()
            ->and($modules->isActive($event, 'menus'))->toBeFalse();
    });
});

describe('the API', function () {
    it('sends the modules with every event, in the list and on its own', function () {
        [$event, $owner] = eventOnPlan('basic');

        $list = $this->actingAs($owner)->getJson('/api/v1/app/events')->assertOk();
        $one = $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}")->assertOk();

        expect($list->json('data.events.0.modules.0'))->toHaveKeys(['key', 'section', 'order', 'state'])
            ->and($one->json('data.modules'))->toHaveCount(13);
    });

    it('does not run more queries for ten events than for one', function () {
        [$first, $owner] = eventOnPlan('basic');
        foreach (range(1, 9) as $i) {
            $event = Events::factory()->draft()->create(['event_type_id' => $first->event_type_id, 'event_plan_id' => $first->event_plan_id]);
            EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        }
        $service = app(EventModules::class);
        $events = Events::all();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $events->each(fn ($event) => $service->forEvent($event));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBeLessThanOrEqual(2);
    });
});

describe('a new event', function () {
    it('starts with the core on and the premium modules off', function () {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/app/event', [
            'eventName' => 'Mis XV', 'eventType' => EventType::where('slug', 'quinceanera')->value('id'), 'startDate' => '12/01/2027 18:00',
        ])->assertSuccessful();

        $features = Events::find($response->json('data.id'))->eventFeature;
        // The flags come back as 0/1 from the database: compare them as booleans.
        $on = array_map('boolval', $features->only(['rsvp', 'save_the_date', 'location', 'budget']));
        $off = array_map('boolval', $features->only(['menu', 'sweet_memories', 'music', 'background_music', 'event_comments', 'seats_accommodation']));
        expect($on)->each->toBeTrue()->and($off)->each->toBeFalse();
        expect(moduleStates($response->json('data.modules')))->toMatchArray(['rsvp' => 'active', 'menus' => 'locked']);
    });

    it('cannot switch modules on by editing the event', function () {
        $owner = User::factory()->create();
        $created = $this->actingAs($owner)->postJson('/api/v1/app/event', [
            'eventName' => 'Mis XV', 'eventType' => EventType::where('slug', 'quinceanera')->value('id'), 'startDate' => '12/01/2027 18:00',
        ])->assertSuccessful();
        $id = $created->json('data.id');

        $this->actingAs($owner)->patchJson("/api/v1/app/event/{$id}", ['menu' => true, 'music' => true, 'sweetMemories' => true, 'eventName' => 'Otro'])->assertOk();

        $features = Events::find($id)->eventFeature;
        expect((bool) $features->menu)->toBeFalse()->and((bool) $features->music)->toBeFalse()->and((bool) $features->sweet_memories)->toBeFalse();
    });
});
