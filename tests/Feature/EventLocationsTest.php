<?php

use App\Models\EventFeature;
use App\Models\EventLocation;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('public');
});

function placeEvent(): array
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
    ]);
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/location"];
}

function place(array $extra = []): array
{
    return array_merge([
        'type' => 'ceremony', 'name' => 'Parroquia San Juan', 'address' => '100 NW 7th St',
        'city' => 'Miami', 'state' => 'FL', 'zipCode' => '33125', 'country' => 'US',
        'latitude' => 25.77, 'longitude' => -80.2, 'directions' => 'Estacionamiento por la calle lateral.',
    ], $extra);
}

it('keeps several places per event and lists them', function () {
    [, $u, $url] = placeEvent();

    $this->actingAs($u['owner'])->postJson($url, place())->assertCreated()->assertJsonPath('data.directions', 'Estacionamiento por la calle lateral.');
    $this->actingAs($u['owner'])->postJson($url, place(['type' => 'reception', 'name' => 'Salón Jardín']))->assertCreated();

    $this->actingAs($u['owner'])->getJson($url)->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.type', 'reception');
});

it('answers an empty list, not a 404, when there are no places', function () {
    [, $u, $url] = placeEvent();

    $this->actingAs($u['owner'])->getJson($url)->assertOk()->assertJsonCount(0, 'data');
});

it('accepts a place without zip code or coordinates, and validates the rest', function () {
    [, $u, $url] = placeEvent();
    $as = fn (array $p) => $this->actingAs($u['owner'])->postJson($url, $p);

    $as(place(['zipCode' => null, 'latitude' => null, 'longitude' => null, 'state' => null]))->assertCreated();
    $as(place(['type' => 'party']))->assertJsonValidationErrors('type');
    $as(place(['name' => '']))->assertJsonValidationErrors('name');
    $as(place(['latitude' => 120]))->assertJsonValidationErrors('latitude');
});

it('updates and deletes a place with its uploaded pictures', function () {
    [, $u, $url] = placeEvent();
    $id = $this->actingAs($u['owner'])->postJson($url, place())->json('data.id');

    $this->actingAs($u['owner'])->putJson("$url/$id", place(['name' => 'Catedral', 'directions' => null]))
        ->assertOk()->assertJsonPath('data.name', 'Catedral')->assertJsonPath('data.directions', null);

    $images = $this->actingAs($u['owner'])->post("$url/$id/images", ['images' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])->assertOk();
    $path = ltrim(str_replace(Storage::disk('public')->url(''), '', $images->json('data.images.0.path')), '/');
    Storage::disk('public')->assertExists($path);

    $this->actingAs($u['owner'])->deleteJson("$url/$id")->assertOk();
    expect(EventLocation::count())->toBe(0);
    Storage::disk('public')->assertMissing($path);
});

it('limits pictures to six and can remove one', function () {
    [, $u, $url] = placeEvent();
    $id = $this->actingAs($u['owner'])->postJson($url, place())->json('data.id');
    $files = fn ($n) => array_map(fn ($i) => UploadedFile::fake()->image("p$i.jpg"), range(1, $n));

    $first = $this->actingAs($u['owner'])->post("$url/$id/images", ['images' => $files(6)], ['Accept' => 'application/json'])->assertOk();
    $this->actingAs($u['owner'])->post("$url/$id/images", ['images' => $files(1)], ['Accept' => 'application/json'])->assertJsonValidationErrors('images');

    $this->actingAs($u['owner'])->deleteJson("$url/$id/images/{$first->json('data.images.0.id')}")->assertOk();
    expect(EventLocation::find($id)->eventLocationImages()->count())->toBe(5);
});

it('only attaches Google photos that this event fetched itself', function () {
    [$event, $u, $url] = placeEvent();
    $id = $this->actingAs($u['owner'])->postJson($url, place())->json('data.id');
    $mine = Storage::disk('public')->url("locations/google/{$event->id}/ChIJ/photo_1.jpg");

    $response = $this->actingAs($u['owner'])->post("$url/$id/images", ['googlePhotos' => [$mine, 'https://evil.test/x.jpg']], ['Accept' => 'application/json'])->assertOk();

    expect($response->json('data.images'))->toHaveCount(1)->and($response->json('data.images.0.source'))->toBe('google');
});

it('keeps viewers and strangers out, and other events places untouched', function () {
    [, $u, $url] = placeEvent();
    [$other] = placeEvent();
    $foreign = EventLocation::create(['event_id' => $other->id, 'type' => 'other', 'name' => 'Ajeno', 'address' => 'x', 'city' => 'x', 'country' => 'US']);

    $this->actingAs($u['viewer'])->getJson($url)->assertOk();
    $this->actingAs($u['viewer'])->postJson($url, place())->assertForbidden();
    $this->actingAs($u['stranger'])->getJson($url)->assertNotFound();
    $this->actingAs($u['owner'])->deleteJson("$url/{$foreign->id}")->assertNotFound();
    $this->actingAs($u['owner'])->putJson("$url/{$foreign->id}", place())->assertNotFound();
    expect(EventLocation::count())->toBe(1);
});

it('gives the invitation the places with their time from the program and route buttons', function () {
    [$event] = placeEvent();
    $event->update(['custom_url_slug' => 'xv-lugares', 'status' => 'published']);
    Guest::factory()->create(['event_id' => $event->id, 'code' => 'AB1234', 'parent_id' => null]);
    $this->seed(InvitationTemplateSeeder::class);
    EventLocation::create(['event_id' => $event->id, 'type' => 'reception', 'name' => 'Salón Jardín', 'address' => '1 Main St', 'city' => 'Miami', 'country' => 'US', 'notes' => 'Valet en la entrada']);
    EventLocation::create(['event_id' => $event->id, 'type' => 'ceremony', 'name' => 'Parroquia', 'address' => '2 Main St', 'city' => 'Miami', 'country' => 'US', 'latitude' => 25.7, 'longitude' => -80.2]);
    Timeline::create(['event_id' => $event->id, 'title' => 'Misa', 'type' => 'ceremony', 'start_time' => '2027-03-20 16:00:00']);

    $items = collect($this->getJson('/api/v1/app/invitation/xv-lugares/AB1234')->json('data.sections'))->firstWhere('type', 'locations')['data']['items'];

    expect(collect($items)->pluck('name')->all())->toBe(['Parroquia', 'Salón Jardín'])
        ->and($items[0]['startTime'])->toBe('2027-03-20 16:00:00')
        ->and($items[1]['startTime'])->toBeNull()
        ->and($items[1]['directions'])->toBe('Valet en la entrada')
        ->and($items[0]['links']['waze'])->toStartWith('https://waze.com/ul?ll=')
        ->and($items[0]['links']['apple'])->toStartWith('https://maps.apple.com/?')
        ->and($items[1]['links']['google'])->toContain('Sal%C3%B3n%20Jard%C3%ADn');
});
