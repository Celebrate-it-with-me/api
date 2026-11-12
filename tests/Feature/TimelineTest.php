<?php

use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Timeline;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function programEvent(string $type = 'quinceanera'): array
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', $type)->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
        'start_date' => '2027-03-20 16:00:00',
    ]);
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/timeline"];
}

function moment(array $extra = []): array
{
    return array_merge(['title' => 'Vals', 'type' => 'waltz', 'startTime' => '2027-03-20 19:00:00'], $extra);
}

it('creates, lists in order, updates and deletes moments', function () {
    [, $u, $url] = programEvent();
    $as = fn () => $this->actingAs($u['owner']);

    $dinner = $as()->postJson($url, moment(['title' => 'Cena', 'type' => 'dinner', 'startTime' => '2027-03-20 20:00:00', 'description' => 'Servicio']))->assertCreated();
    $as()->postJson($url, moment())->assertCreated();

    $list = $as()->getJson($url)->assertOk()->json('data');
    expect(collect($list)->pluck('title')->all())->toBe(['Vals', 'Cena'])
        ->and($list[1])->toMatchArray(['type' => 'dinner', 'description' => 'Servicio', 'startTime' => '2027-03-20 20:00:00', 'endTime' => null]);

    $as()->putJson("$url/{$dinner->json('data.id')}", moment(['title' => 'Cena servida', 'type' => 'dinner', 'endTime' => '2027-03-20 21:00:00']))
        ->assertOk()->assertJsonPath('data.title', 'Cena servida')->assertJsonPath('data.endTime', '2027-03-20 21:00:00');

    $as()->deleteJson("$url/{$dinner->json('data.id')}")->assertOk();
    expect(Timeline::count())->toBe(1);
});

it('validates the moment', function () {
    [, $u, $url] = programEvent();
    $as = fn (array $p) => $this->actingAs($u['owner'])->postJson($url, $p);

    $as(moment(['title' => '']))->assertJsonValidationErrors('title');
    $as(moment(['type' => 'party']))->assertJsonValidationErrors('type');
    $as(moment(['startTime' => 'mañana']))->assertJsonValidationErrors('startTime');
    $as(moment(['endTime' => '2027-03-20 18:00:00']))->assertJsonValidationErrors('endTime');
});

it('starts with a typical program only when the program is empty', function () {
    [$event, $u, $url] = programEvent();

    $response = $this->actingAs($u['owner'])->postJson("$url/typical")->assertCreated();
    expect(collect($response->json('data'))->pluck('type')->all())->toBe(['ceremony', 'reception', 'waltz', 'dinner', 'dance', 'other'])
        ->and($response->json('data.0.startTime'))->toBe('2027-03-20 16:00:00')
        ->and($response->json('data.1.startTime'))->toBe('2027-03-20 17:30:00');

    $this->actingAs($u['owner'])->postJson("$url/typical")->assertStatus(422);
    expect(Timeline::where('event_id', $event->id)->count())->toBe(6);
});

it('has a typical program for weddings', function () {
    [, $u, $url] = programEvent('wedding');

    $types = collect($this->actingAs($u['owner'])->postJson("$url/typical")->json('data'))->pluck('title')->all();

    expect($types)->toBe(['Ceremonia', 'Cóctel', 'Cena', 'Primer baile', 'Fiesta']);
});

it('keeps viewers and strangers from changing the program', function () {
    [$event, $u, $url] = programEvent();
    $own = Timeline::create(['event_id' => $event->id, 'title' => 'Vals', 'type' => 'waltz', 'start_time' => '2027-03-20 19:00:00']);

    $this->actingAs($u['viewer'])->getJson($url)->assertOk();
    $this->actingAs($u['viewer'])->postJson($url, moment())->assertForbidden();
    $this->actingAs($u['viewer'])->postJson("$url/typical")->assertForbidden();
    $this->actingAs($u['viewer'])->deleteJson("$url/{$own->id}")->assertForbidden();
    $this->actingAs($u['stranger'])->getJson($url)->assertNotFound();
    $this->actingAs($u['stranger'])->deleteJson("$url/{$own->id}")->assertNotFound();
    expect(Timeline::count())->toBe(1);
});

it('does not touch the moment of another event', function () {
    [, $u, $url] = programEvent();
    [$other] = programEvent();
    $foreign = Timeline::create(['event_id' => $other->id, 'title' => 'Ajeno', 'type' => 'other', 'start_time' => '2027-03-20 19:00:00']);

    $this->actingAs($u['owner'])->deleteJson("$url/{$foreign->id}")->assertStatus(404);
    expect(Timeline::count())->toBe(1);
});

it('shows the program in the public invitation, in order', function () {
    [$event, $u] = programEvent();
    $event->update(['custom_url_slug' => 'xv-prueba', 'status' => 'published']);
    $guest = Guest::factory()->create(['event_id' => $event->id, 'code' => 'AB1234', 'parent_id' => null]);
    $this->seed(InvitationTemplateSeeder::class);

    $sections = collect($this->getJson('/api/v1/app/invitation/xv-prueba/AB1234')->json('data.sections'))->pluck('type');
    expect($sections->contains('itinerary'))->toBeFalse();

    Timeline::create(['event_id' => $event->id, 'title' => 'Cena', 'type' => 'dinner', 'start_time' => '2027-03-20 20:00:00']);
    Timeline::create(['event_id' => $event->id, 'title' => 'Vals', 'type' => 'waltz', 'start_time' => '2027-03-20 19:00:00', 'end_time' => '2027-03-20 19:30:00']);

    $section = collect($this->getJson('/api/v1/app/invitation/xv-prueba/AB1234')->json('data.sections'))->firstWhere('type', 'itinerary');
    expect($section['data']['items'])->toHaveCount(2)
        ->and($section['data']['items'][0])->toMatchArray(['title' => 'Vals', 'type' => 'waltz', 'endTime' => '2027-03-20 19:30:00']);
});
