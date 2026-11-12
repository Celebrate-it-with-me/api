<?php

use App\Models\DressCode;
use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('public');
});

/** An event with an owner, a viewer and a stranger. */
function dressEvent(): array
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

    return [$event, $users, "/api/v1/app/event/{$event->id}/dress-code"];
}

function dressPayload(array $extra = []): array
{
    return array_merge(['dressCodeType' => 'formal', 'description' => 'Elegante'], $extra);
}

it('answers an empty dress code when the event has none', function () {
    [, $u, $url] = dressEvent();

    $this->actingAs($u['owner'])->getJson($url)->assertOk()
        ->assertJsonPath('data.dressCodeType', null)
        ->assertJsonPath('data.reservedColors', [])
        ->assertJsonPath('data.images', []);
});

it('saves type, description and both palettes, normalizing the colors', function () {
    [$event, $u, $url] = dressEvent();

    $this->actingAs($u['owner'])->postJson($url, dressPayload([
        'dressCodeType' => 'cocktail', 'reservedColors' => ['#FFF', '#ffffff', '#AABBCC'], 'suggestedColors' => ['#112233'],
    ]))->assertOk()
        ->assertJsonPath('data.dressCodeType', 'cocktail')
        ->assertJsonPath('data.reservedColors', ['#ffffff', '#aabbcc'])
        ->assertJsonPath('data.suggestedColors', ['#112233']);

    expect(DressCode::where('event_id', $event->id)->count())->toBe(1);
});

it('replaces the dress code instead of creating a second one', function () {
    [$event, $u, $url] = dressEvent();
    $this->actingAs($u['owner'])->postJson($url, dressPayload())->assertOk();
    $this->actingAs($u['owner'])->postJson($url, dressPayload(['dressCodeType' => 'black-tie']))->assertOk();

    expect(DressCode::where('event_id', $event->id)->count())->toBe(1)
        ->and(DressCode::where('event_id', $event->id)->value('dress_code_type'))->toBe('black-tie');
});

it('validates type, colors and amounts', function () {
    [, $u, $url] = dressEvent();
    $as = fn (array $p) => $this->actingAs($u['owner'])->postJson($url, $p);

    $as(dressPayload(['dressCodeType' => 'pijama']))->assertJsonValidationErrors('dressCodeType');
    $as(dressPayload(['reservedColors' => ['rojo']]))->assertJsonValidationErrors('reservedColors.0');
    $as(dressPayload(['suggestedColors' => array_fill(0, 9, '#000000')]))->assertJsonValidationErrors('suggestedColors');
    $as(dressPayload(['bankImages' => ['../x']]))->assertJsonValidationErrors('bankImages.0');
});

it('stores uploads and limits the pictures to six', function () {
    [, $u, $url] = dressEvent();
    $file = fn ($n) => UploadedFile::fake()->image("p$n.jpg");

    $response = $this->actingAs($u['owner'])->post($url, dressPayload(['dressCodeImages' => [$file(1), $file(2)]]), ['Accept' => 'application/json'])->assertOk();
    expect($response->json('data.images'))->toHaveCount(2);
    Storage::disk('public')->assertExists('dress-code-images/' . basename(parse_url($response->json('data.images.0.url'), PHP_URL_PATH)));

    $this->actingAs($u['owner'])->post($url, dressPayload([
        'keepImageIds' => collect($response->json('data.images'))->pluck('id')->all(),
        'bankImages' => ['formal/a', 'formal/b', 'formal/c'],
        'dressCodeImages' => [$file(3), $file(4)],
    ]), ['Accept' => 'application/json'])->assertJsonValidationErrors('dressCodeImages');
});

it('syncs bank picks and removes uploads that are not kept, deleting their files', function () {
    [, $u, $url] = dressEvent();
    $first = $this->actingAs($u['owner'])->post($url, dressPayload(['bankImages' => ['formal/a', 'formal/b'], 'dressCodeImages' => [UploadedFile::fake()->image('p.jpg')]]), ['Accept' => 'application/json'])->assertOk();
    $upload = collect($first->json('data.images'))->firstWhere('source', 'upload');
    $path = 'dress-code-images/' . basename(parse_url($upload['url'], PHP_URL_PATH));
    Storage::disk('public')->assertExists($path);

    $second = $this->actingAs($u['owner'])->postJson($url, dressPayload(['bankImages' => ['formal/b', 'formal/c']]))->assertOk();

    expect(collect($second->json('data.images'))->pluck('bankKey')->filter()->sort()->values()->all())->toBe(['formal/b', 'formal/c'])
        ->and(collect($second->json('data.images'))->where('source', 'upload'))->toBeEmpty();
    Storage::disk('public')->assertMissing($path);
});

it('deletes the dress code with its files', function () {
    [$event, $u, $url] = dressEvent();
    $saved = $this->actingAs($u['owner'])->post($url, dressPayload(['dressCodeImages' => [UploadedFile::fake()->image('p.jpg')]]), ['Accept' => 'application/json']);
    $path = 'dress-code-images/' . basename(parse_url($saved->json('data.images.0.url'), PHP_URL_PATH));

    $this->actingAs($u['owner'])->deleteJson($url)->assertOk();

    expect(DressCode::where('event_id', $event->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($path);
});

it('lets a viewer read but not change, and hides the event from strangers', function () {
    [, $u, $url] = dressEvent();

    $this->actingAs($u['viewer'])->getJson($url)->assertOk();
    $this->actingAs($u['viewer'])->postJson($url, dressPayload())->assertForbidden();
    $this->actingAs($u['viewer'])->deleteJson($url)->assertForbidden();
    $this->actingAs($u['stranger'])->getJson($url)->assertNotFound();
    $this->actingAs($u['stranger'])->postJson($url, dressPayload())->assertNotFound();
});

it('maps the legacy casual type to smart-casual in the migration', function () {
    expect(DressCode::TYPES)->toContain('smart-casual')->not->toContain('casual');
});
