<?php

use App\Http\Services\AppServices\GuestLimit;
use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** An event on the given plan, with an owner. */
function limitedEvent(string $plan = 'basic'): array
{
    $owner = User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
    EventFeature::create(['event_id' => $event->id]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner];
}

/** $mains main guests, each with $companions companions: $mains * (1 + $companions) people. */
function loadPeople(Events $event, int $mains, int $companions = 0): void
{
    Guest::factory()->count($mains)->create(['event_id' => $event->id, 'parent_id' => null])->each(function (Guest $main) use ($companions, $event) {
        Guest::factory()->count($companions)->create(['event_id' => $event->id, 'parent_id' => $main->id]);
    });
}

function guestPayload(int $named = 0, int $unnamed = 0): array
{
    return [
        'guest' => ['name' => 'Invitado Nuevo'],
        // range(1, 0) counts backwards in PHP, so an empty list is spelled out.
        'namedCompanions' => $named > 0 ? array_map(fn ($i) => ['name' => "Acompañante $i"], range(1, $named)) : [],
        'unnamedCompanions' => $unnamed,
    ];
}

describe('the plan limit', function () {
    it('is 30 people on the free plan, and the free plan is the entry one', function () {
        [$free] = limitedEvent('basic');
        [$premium] = limitedEvent('premium');
        [$lux] = limitedEvent('lux');

        expect(app(GuestLimit::class)->limitFor($free))->toBe(30)
            ->and(app(GuestLimit::class)->limitFor($premium))->toBe(200)
            ->and(app(GuestLimit::class)->limitFor($lux))->toBeNull();
    });

    it('reaches the screens as guestLimit, null when the plan has none', function () {
        [$free, $owner] = limitedEvent('basic');
        [$lux, $luxOwner] = limitedEvent('lux');

        expect($this->actingAs($owner)->getJson("/api/v1/app/event/{$free->id}")->json('data.guestLimit'))->toBe(30)
            ->and($this->actingAs($luxOwner)->getJson("/api/v1/app/event/{$lux->id}")->json('data.guestLimit'))->toBeNull();
    });
});

describe('publishing', function () {
    it('is allowed with exactly 30 people, counting companions', function () {
        [$event, $owner] = limitedEvent();
        loadPeople($event, 15, 1); // 15 + 15 = 30

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();
    });

    it('is blocked with 31, with the numbers the screen needs', function () {
        [$event, $owner] = limitedEvent();
        loadPeople($event, 10, 2); // 30
        loadPeople($event, 1);     // 31

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/publish")
            ->assertStatus(402)
            ->assertJson(['code' => 'guest_limit_exceeded', 'limit' => 30, 'total' => 31, 'excess' => 1, 'upgrade' => true]);

        expect($event->fresh()->status)->toBe('draft')->and($event->fresh()->published_at)->toBeNull();
    });

    it('is possible again after removing the excess', function () {
        [$event, $owner] = limitedEvent();
        loadPeople($event, 33);

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/publish")->assertStatus(402)->assertJsonPath('excess', 3);

        Guest::where('event_id', $event->id)->limit(3)->get()->each->delete();

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();
    });

    it('has no limit on a plan without one, and respects the paid plan\'s own', function () {
        [$lux, $luxOwner] = limitedEvent('lux');
        loadPeople($lux, 250);
        $this->actingAs($luxOwner)->postJson("/api/v1/app/event/{$lux->id}/publish")->assertOk();

        [$premium, $premiumOwner] = limitedEvent('premium');
        loadPeople($premium, 201);
        $this->actingAs($premiumOwner)->postJson("/api/v1/app/event/{$premium->id}/publish")
            ->assertStatus(402)->assertJson(['limit' => 200, 'total' => 201]);
    });
});

describe('loading the list', function () {
    it('is free while the event is a draft: it can go past 30', function () {
        [$event, $owner] = limitedEvent();
        loadPeople($event, 30);

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/guests", guestPayload(unnamed: 5))->assertSuccessful();

        expect(Guest::where('event_id', $event->id)->count())->toBe(36);
    });

    it('has a hard cap once published: guest 31 is refused', function () {
        [$event, $owner] = limitedEvent();
        loadPeople($event, 30);
        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/guests", guestPayload())
            ->assertStatus(402)->assertJson(['code' => 'guest_limit_exceeded', 'limit' => 30, 'total' => 31]);

        expect(Guest::where('event_id', $event->id)->count())->toBe(30);
    });

    it('counts the companions of the new guest, and allows reaching exactly 30', function () {
        [$event, $owner] = limitedEvent();
        loadPeople($event, 27);
        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();

        // 27 + (1 + 2 named + 1 unnamed) = 31: refused, and nothing is created
        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/guests", guestPayload(named: 2, unnamed: 1))
            ->assertStatus(402)->assertJson(['total' => 31]);
        expect(Guest::where('event_id', $event->id)->count())->toBe(27);

        // 27 + (1 + 2) = 30: allowed
        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/guests", guestPayload(named: 2))->assertSuccessful();
        expect(Guest::where('event_id', $event->id)->count())->toBe(30);
    });

    it('is free again after going back to draft, but publishing waits for the list to fit', function () {
        [$event, $owner] = limitedEvent();
        loadPeople($event, 30);
        $api = "/api/v1/app/event/{$event->id}";
        $this->actingAs($owner)->postJson("$api/publish")->assertOk();
        $this->actingAs($owner)->postJson("$api/unpublish")->assertOk();

        $this->actingAs($owner)->postJson("$api/guests", guestPayload())->assertSuccessful();
        $this->actingAs($owner)->postJson("$api/publish")->assertStatus(402)->assertJson(['total' => 31]);
    });

    it('never limits a paid plan without a cap', function () {
        [$event, $owner] = limitedEvent('lux');
        loadPeople($event, 250);
        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/guests", guestPayload(unnamed: 10))->assertSuccessful();
    });
});
