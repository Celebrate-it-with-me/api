<?php

use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\SweetMemoriesImage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('sweet-memories');
});

function memoriesEvent(): array
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

    return [$event, $users, "/api/v1/app/event/{$event->id}/sweet-memories-images"];
}

function uploadImage($test, $user, string $url, array $extra = []): array
{
    $response = $test->actingAs($user)->post($url, array_merge([
        'files' => [UploadedFile::fake()->image('foto.jpg', 400, 300)],
        'metadata' => [['name' => 'foto.jpg', 'size' => 1234]],
        'title' => 'Mi foto',
    ], $extra), ['Accept' => 'application/json']);

    return [$response, $response->json('data.0.id') ?? $response->json('0.id')];
}

it('uploads images with their thumbnails and removes the files when deleted', function () {
    [$event, $u, $url] = memoriesEvent();

    [$response] = uploadImage($this, $u['owner'], $url);
    $response->assertSuccessful();
    $image = SweetMemoriesImage::where('event_id', $event->id)->firstOrFail();
    Storage::disk('sweet-memories')->assertExists($image->image_path);
    Storage::disk('sweet-memories')->assertExists($image->thumbnail_path);

    $this->actingAs($u['owner'])->deleteJson("$url/{$image->id}")->assertOk();
    Storage::disk('sweet-memories')->assertMissing($image->image_path);
    Storage::disk('sweet-memories')->assertMissing($image->thumbnail_path);
});

it('gives two uploads with the same file name their own thumbnail', function () {
    [$event, $u, $url] = memoriesEvent();

    uploadImage($this, $u['owner'], $url);
    uploadImage($this, $u['owner'], $url);
    [$a, $b] = SweetMemoriesImage::where('event_id', $event->id)->orderBy('id')->get()->all();

    expect($a->thumbnail_path)->not->toBe($b->thumbnail_path);
    $this->actingAs($u['owner'])->deleteJson("$url/{$a->id}")->assertOk();
    Storage::disk('sweet-memories')->assertExists($b->thumbnail_path);
});

it('only accepts real images of a reasonable size', function () {
    [, $u, $url] = memoriesEvent();
    $post = fn (array $files) => $this->actingAs($u['owner'])->post($url, ['files' => $files], ['Accept' => 'application/json']);

    $post([UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf')])->assertStatus(422)->assertJsonValidationErrors('files.0');
    $post([UploadedFile::fake()->image('enorme.jpg')->size(9000)])->assertStatus(422)->assertJsonValidationErrors('files.0');
    $this->actingAs($u['owner'])->post($url, [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('files');
    expect(SweetMemoriesImage::count())->toBe(0);
});

it('uploads without metadata instead of failing with a 500', function () {
    [$event, $u, $url] = memoriesEvent();

    $this->actingAs($u['owner'])->post($url, ['files' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])->assertSuccessful();

    expect(SweetMemoriesImage::where('event_id', $event->id)->count())->toBe(1);
});

it('edits the details of an image without sending a file', function () {
    [$event, $u, $url] = memoriesEvent();
    uploadImage($this, $u['owner'], $url);
    $image = SweetMemoriesImage::where('event_id', $event->id)->firstOrFail();

    $this->actingAs($u['owner'])->putJson("$url/{$image->id}", ['title' => 'Nuevo título', 'visible' => false, 'order' => 3])->assertOk();

    expect($image->fresh())->title->toBe('Nuevo título')->and($image->fresh()->visible)->toBeFalsy();
});

it('keeps viewers read-only and strangers out', function () {
    [$event, $u, $url] = memoriesEvent();
    uploadImage($this, $u['owner'], $url);
    $image = SweetMemoriesImage::where('event_id', $event->id)->firstOrFail();

    $this->actingAs($u['viewer'])->getJson($url)->assertOk();
    $this->actingAs($u['viewer'])->deleteJson("$url/{$image->id}")->assertForbidden();
    $this->actingAs($u['viewer'])->putJson("$url/{$image->id}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($u['stranger'])->getJson($url)->assertNotFound();
    expect(SweetMemoriesImage::count())->toBe(1);
});

it('cannot touch the image of another event, by any route', function () {
    [$event, $u, $url] = memoriesEvent();
    [$other, $u2, $url2] = memoriesEvent();
    uploadImage($this, $u2['owner'], $url2);
    $foreign = SweetMemoriesImage::where('event_id', $other->id)->firstOrFail();

    $this->actingAs($u['owner'])->putJson("$url/{$foreign->id}", ['title' => 'robado'])->assertNotFound();
    $this->actingAs($u['owner'])->deleteJson("$url/{$foreign->id}")->assertNotFound();
    // The standalone rename route had no event at all: any logged-in user could rename any image.
    $this->actingAs($u['owner'])->patchJson("/api/v1/app/sweet-memories-images/{$foreign->id}", ['name' => 'robado'])->assertNotFound();
    $this->actingAs($u['stranger'])->patchJson("/api/v1/app/sweet-memories-images/{$foreign->id}", ['name' => 'robado'])->assertNotFound();

    expect($foreign->fresh()->title)->not->toBe('robado')->and($foreign->fresh()->image_original_name)->not->toBe('robado');
    Storage::disk('sweet-memories')->assertExists($foreign->image_path);
});

it('renames an image of its own event through the standalone route', function () {
    [$event, $u, $url] = memoriesEvent();
    uploadImage($this, $u['owner'], $url);
    $image = SweetMemoriesImage::where('event_id', $event->id)->firstOrFail();

    $this->actingAs($u['owner'])->patchJson("/api/v1/app/sweet-memories-images/{$image->id}", ['name' => 'Cumpleaños'])->assertOk();
    $this->actingAs($u['viewer'])->patchJson("/api/v1/app/sweet-memories-images/{$image->id}", ['name' => 'x'])->assertStatus(404);

    expect($image->fresh()->image_original_name)->toBe('Cumpleaños');
});
