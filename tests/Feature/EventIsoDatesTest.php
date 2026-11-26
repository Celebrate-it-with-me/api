<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function isoEvent(array $attrs): array
{
    $event = Events::factory()->create(array_merge([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'custom_url_slug' => 'xv-de-valentina',
    ], $attrs));
    $owner = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner];
}

it('adds ISO dates with the event zone offset, in winter and in summer', function (string $tz, string $start, string $startIso) {
    [$event, $owner] = isoEvent(['timezone' => $tz, 'start_date' => $start, 'end_date' => null]);

    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}")->assertOk()
        ->assertJsonPath('data.startsAt', $startIso)
        ->assertJsonPath('data.endsAt', null);
})->with([
    'New York winter (EST)' => ['America/New_York', '2027-01-02 19:00:00', '2027-01-02T19:00:00-05:00'],
    'New York summer (EDT)' => ['America/New_York', '2027-07-02 19:00:00', '2027-07-02T19:00:00-04:00'],
    'Bogota, no DST' => ['America/Bogota', '2027-07-02 19:00:00', '2027-07-02T19:00:00-05:00'],
    'Phoenix, no DST' => ['America/Phoenix', '2027-07-02 19:00:00', '2027-07-02T19:00:00-07:00'],
]);

it('keeps the legacy text dates and handles an event that crosses midnight', function () {
    [$event, $owner] = isoEvent(['timezone' => 'America/New_York', 'start_date' => '2027-01-02 19:00:00', 'end_date' => '2027-01-03 01:00:00']);

    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}")->assertOk()
        ->assertJsonPath('data.startDate', '01/02/2027 19:00')
        ->assertJsonPath('data.endDate', '01/03/2027 01:00')
        ->assertJsonPath('data.startsAt', '2027-01-02T19:00:00-05:00')
        ->assertJsonPath('data.endsAt', '2027-01-03T01:00:00-05:00');
});

it('returns the ISO dates in the list and in the hydration too', function () {
    [$event, $owner] = isoEvent(['timezone' => 'America/New_York', 'start_date' => '2027-07-02 19:00:00', 'end_date' => '2027-07-02 23:30:00']);
    $owner->forceFill(['last_active_event_id' => $event->id])->save();

    $list = $this->actingAs($owner)->getJson('/api/v1/app/events')->assertOk();
    expect(collect($list->json('data.events') ?? $list->json('events'))->firstWhere('id', $event->id)['startsAt'])->toBe('2027-07-02T19:00:00-04:00');

    $this->actingAs($owner)->getJson("/api/v1/app/user/hydrate/{$owner->id}")->assertOk()
        ->assertJsonPath('activeEvent.startsAt', '2027-07-02T19:00:00-04:00')
        ->assertJsonPath('activeEvent.endsAt', '2027-07-02T23:30:00-04:00');
});

it('exposes the public invitation address to the people who can see the event', function () {
    [$event, $owner] = isoEvent(['timezone' => 'America/New_York', 'start_date' => '2027-07-02 19:00:00']);

    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}")->assertOk()
        ->assertJsonPath('data.publicUrl', rtrim(config('app.frontend_app.url'), '/') . '/i/xv-de-valentina');

    $this->actingAs(User::factory()->create())->getJson("/api/v1/app/event/{$event->id}")->assertNotFound();
});

it('has no public address when the front url is not configured', function () {
    [$event, $owner] = isoEvent(['start_date' => '2027-07-02 19:00:00']);
    config(['app.frontend_app.url' => '']);

    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}")->assertJsonPath('data.publicUrl', null);
});
