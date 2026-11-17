<?php

use App\Models\BackgroundMusic;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\GuestRsvpLog;
use App\Models\Module;
use App\Models\SweetMemoriesImage;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('public');
    Storage::fake('sweet-memories');
});

const GM_INVITATION = '/api/v1/app/invitation/xv-galeria/GAL123';

/** A published event with a main guest (code GAL123) and two companions, on the given plan. */
function galleryEvent(string $plan = 'premium', array $event = []): array
{
    $event = Events::factory()->create(array_merge([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => 'published',
        'custom_url_slug' => 'xv-galeria',
    ], $event));
    $main = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Rosa Fernández', 'code' => 'GAL123', 'rsvp_status' => 'pending']);
    $companions = Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => $main->id, 'rsvp_status' => 'pending']);

    return [$event, $main, $companions];
}

function addPhotos(Events $event, int $count = 3, array $attributes = []): void
{
    foreach (range(1, $count) as $i) {
        SweetMemoriesImage::factory()->create(array_merge([
            'event_id' => $event->id,
            'image_path' => "images/event/{$event->id}/memories-images/p{$i}.jpg",
            'thumbnail_path' => "images/event/{$event->id}/memories-thumbnails/thumbnail_p{$i}.jpg",
            'title' => "Foto {$i}",
            'year' => '2016',
            'order' => $i,
        ], $attributes));
    }
}

function addSong(Events $event, array $attributes = []): BackgroundMusic
{
    $path = "sound/background-music/{$event->id}/song.mp3";
    Storage::disk('public')->put($path, 'ID3');

    return BackgroundMusic::create(array_merge([
        'event_id' => $event->id, 'song_url' => $path, 'title' => 'Nuestra canción',
        'icon_size' => 'lg', 'icon_position' => 'bl', 'icon_color' => '#ff0000', 'auto_play' => true,
    ], $attributes));
}

function sectionTypes($response): array
{
    return collect($response->json('data.sections'))->pluck('type')->all();
}

function sectionOf($response, string $type): ?array
{
    return collect($response->json('data.sections'))->firstWhere('type', $type);
}

describe('gallery and music in the public invitation', function () {
    it('gives the basic plan neither section, even with photos and a song', function () {
        [$event] = galleryEvent('basic');
        addPhotos($event);
        addSong($event);

        $types = sectionTypes($this->getJson(GM_INVITATION)->assertOk());

        expect($types)->not->toContain('gallery')->not->toContain('music');
    });

    it('gives the full event both sections in order, with whitelisted settings and no autoplay', function () {
        [$event] = galleryEvent();
        addPhotos($event, 3);
        addSong($event);

        $response = $this->getJson(GM_INVITATION . '?lang=en')->assertOk();
        $types = sectionTypes($response);

        expect(array_search('gallery', $types))->toBeLessThan(array_search('rsvp', $types))
            ->and(end($types))->toBe('music')
            ->and(sectionOf($response, 'gallery')['title'])->toBe('My memories')
            ->and(sectionOf($response, 'gallery')['settings'])->toBe(['layout' => 'grid', 'maxPhotos' => 12, 'showCaptions' => true]);

        $photos = sectionOf($response, 'gallery')['data']['photos'];
        expect($photos)->toHaveCount(3)
            ->and(array_keys($photos[0]))->toBe(['id', 'url', 'thumbUrl', 'title', 'description', 'year'])
            ->and($photos[0]['title'])->toBe('Foto 1')
            ->and($photos[0]['year'])->toBe(2016)
            ->and($photos[0]['url'])->toEndWith("images/event/{$event->id}/memories-images/p1.jpg")
            ->and($photos[0]['thumbUrl'])->toEndWith('thumbnail_p1.jpg');

        $music = sectionOf($response, 'music');
        expect($music['data'])->toBe([
            'url' => url(Storage::url("sound/background-music/{$event->id}/song.mp3")),
            'title' => 'Nuestra canción',
            'mimeType' => 'audio/mpeg',
            'loop' => true,
        ])->and($music['settings'])->toBe(['title' => null]);

        // Nothing about autoplay or the icon travels to the guest.
        expect($response->getContent())->not->toContain('autoplay')->not->toContain('iconSize')->not->toContain('#ff0000')->not->toContain('auto_play');
    });

    it('leaves a section out when it has no content', function () {
        [$event] = galleryEvent();

        expect(sectionTypes($this->getJson(GM_INVITATION)->assertOk()))->not->toContain('gallery')->not->toContain('music');

        // A song whose file is gone is no song.
        $song = addSong($event);
        Storage::disk('public')->delete($song->song_url);

        expect(sectionTypes($this->getJson(GM_INVITATION)->assertOk()))->not->toContain('music');
    });

    it('hides photos that are not visible or were hidden by moderation', function () {
        [$event] = galleryEvent();
        addPhotos($event, 1, ['title' => 'Se ve']);
        addPhotos($event, 1, ['title' => 'Oculta por la organizadora', 'visible' => false, 'image_path' => 'a/hidden1.jpg']);
        addPhotos($event, 1, ['title' => 'Moderada', 'hidden_at' => now(), 'image_path' => 'a/hidden2.jpg']);

        $photos = sectionOf($this->getJson(GM_INVITATION)->assertOk(), 'gallery')['data']['photos'];

        expect(collect($photos)->pluck('title')->all())->toBe(['Se ve']);
    });

    it('shows no gallery when every photo is hidden', function () {
        [$event] = galleryEvent();
        addPhotos($event, 2, ['hidden_at' => now()]);

        expect(sectionTypes($this->getJson(GM_INVITATION)->assertOk()))->not->toContain('gallery');
    });

    it('orders by order then id and respects maxPhotos with a hard cap of 40', function () {
        [$event] = galleryEvent();
        addPhotos($event, 45, ['order' => 0]);
        SweetMemoriesImage::query()->where('event_id', $event->id)->orderByDesc('id')->first()->update(['order' => -1, 'title' => 'Primera']);

        // The organizer asks for 99 photos and sneaks in a key that does not exist.
        $event->update(['template_config' => ['sections' => [['type' => 'gallery', 'settings' => ['maxPhotos' => 99, 'layout' => 'carousel', 'secret' => 'x']]]]]);
        $response = $this->getJson(GM_INVITATION)->assertOk();
        $gallery = sectionOf($response, 'gallery');

        expect($gallery['data']['photos'])->toHaveCount(40)
            ->and($gallery['data']['photos'][0]['title'])->toBe('Primera')
            ->and($gallery['settings'])->toBe(['layout' => 'carousel', 'maxPhotos' => 40, 'showCaptions' => true])
            ->and($response->getContent())->not->toContain('secret');

        $event->update(['template_config' => ['sections' => [['type' => 'gallery', 'settings' => ['maxPhotos' => 2, 'layout' => 'masonry', 'showCaptions' => false]]]]]);
        $gallery = sectionOf($this->getJson(GM_INVITATION)->assertOk(), 'gallery');

        expect($gallery['data']['photos'])->toHaveCount(2)
            ->and($gallery['settings'])->toBe(['layout' => 'grid', 'maxPhotos' => 2, 'showCaptions' => false]);
    });

    it('uses the localized music title from the settings, trimmed to 60 characters', function () {
        [$event] = galleryEvent();
        addSong($event, ['title' => null]);
        $event->update(['template_config' => ['sections' => [['type' => 'music', 'settings' => ['title' => ['es' => 'Nuestra canción', 'en' => str_repeat('a', 70)]]]]]]);

        $es = sectionOf($this->getJson(GM_INVITATION)->assertOk(), 'music');
        $en = sectionOf($this->getJson(GM_INVITATION . '?lang=en')->assertOk(), 'music');

        expect($es['settings']['title'])->toBe('Nuestra canción')
            ->and($es['data']['title'])->toBe('Nuestra canción')
            ->and(mb_strlen($en['settings']['title']))->toBe(60);
    });

    it('drops both sections when the event goes back to the basic plan (files stay)', function () {
        [$event] = galleryEvent();
        addPhotos($event);
        $song = addSong($event);
        expect(sectionTypes($this->getJson(GM_INVITATION)->assertOk()))->toContain('gallery')->toContain('music');

        $event->update(['event_plan_id' => EventPlan::where('slug', 'basic')->value('id')]);

        expect(sectionTypes($this->getJson(GM_INVITATION)->assertOk()))->not->toContain('gallery')->not->toContain('music');
        Storage::disk('public')->assertExists($song->song_url);
    });

    it('follows the admin: switching a module off removes its section', function () {
        [$event] = galleryEvent();
        addPhotos($event);
        addSong($event);
        Module::where('key', 'background_music')->update(['is_enabled' => false]);

        expect(sectionTypes($this->getJson(GM_INVITATION)->assertOk()))->toContain('gallery')->not->toContain('music');
    });

    it('does not put either section in the Save the date', function () {
        [$event] = galleryEvent('premium', ['invitation_stage' => Events::STAGE_SAVE_THE_DATE]);
        addPhotos($event);
        addSong($event);

        expect(sectionTypes($this->getJson(GM_INVITATION)->assertOk()))->not->toContain('gallery')->not->toContain('music');
    });
});

describe('the catalog and the Classic templates', function () {
    it('creates background_music as premium and never overwrites the admin', function () {
        $module = Module::where('key', 'background_music')->first();
        expect($module)->not->toBeNull()
            ->and($module->is_enabled)->toBeTrue()
            ->and($module->plans()->pluck('slug')->all())->not->toContain('basic')->toContain('premium');

        $module->update(['sort_order' => 5, 'is_enabled' => false]);
        $module->plans()->detach();
        (new ModuleSeeder)->run();

        $module->refresh();
        expect($module->sort_order)->toBe(5)->and($module->is_enabled)->toBeFalse()->and($module->plans()->count())->toBe(0);
    });

    it('seeds the Classic templates with gallery and music', function () {
        $sections = DB::table('invitation_templates')->where('slug', 'classic-quinceanera')->value('default_sections');
        $types = array_column(json_decode($sections, true), 'type');

        expect($types)->toBe(['cover', 'itinerary', 'locations', 'dress_code', 'padrinos', 'gallery', 'rsvp', 'tables', 'comments', 'closing', 'music']);
    });

    it('publishes a next version for old Classic templates and moves their events to it', function () {
        $row = DB::table('invitation_templates')->where('slug', 'classic-wedding')->first();
        $old = DB::table('invitation_template_versions')->find($row->current_version_id);
        $legacy = collect(json_decode($old->sections, true))->reject(fn ($s) => in_array($s['type'], ['gallery', 'music']))->values()->all();
        DB::table('invitation_template_versions')->where('id', $old->id)->update(['sections' => json_encode($legacy)]);
        DB::table('invitation_templates')->where('id', $row->id)->update(['default_sections' => json_encode($legacy)]);
        $event = Events::factory()->create(['template_version_id' => $old->id]);

        (include database_path('migrations/2027_04_11_000004_add_gallery_and_music_to_classic_templates.php'))->up();
        (include database_path('migrations/2027_04_11_000004_add_gallery_and_music_to_classic_templates.php'))->up(); // idempotent

        $row = DB::table('invitation_templates')->find($row->id);
        $new = DB::table('invitation_template_versions')->find($row->current_version_id);
        expect($new->id)->not->toBe($old->id)
            ->and($new->version)->toBe($old->version + 1)
            ->and($new->status)->toBe('published')
            ->and(DB::table('invitation_template_versions')->find($old->id)->status)->toBe('archived')
            ->and(array_column(json_decode($new->sections, true), 'type'))->toContain('gallery')->toContain('music')
            ->and($event->fresh()->template_version_id)->toBe($new->id)
            ->and(DB::table('invitation_template_versions')->where('template_id', $row->id)->count())->toBe(2);
    });

    it('does not switch gallery/music on for events that already had photos or a song when migrating', function () {
        $row = DB::table('invitation_templates')->where('slug', 'classic-quinceanera')->first();
        $old = DB::table('invitation_template_versions')->find($row->current_version_id);
        $legacy = collect(json_decode($old->sections, true))->reject(fn ($s) => in_array($s['type'], ['gallery', 'music']))->values()->all();
        DB::table('invitation_template_versions')->where('id', $old->id)->update(['sections' => json_encode($legacy)]);
        DB::table('invitation_templates')->where('id', $row->id)->update(['default_sections' => json_encode($legacy)]);

        $quince = EventType::where('slug', 'quinceanera')->value('id');
        $plan = EventPlan::where('slug', 'premium')->value('id');
        $make = fn () => Events::factory()->create(['template_version_id' => $old->id, 'event_type_id' => $quince, 'event_plan_id' => $plan]);
        $withPhotos = $make();
        addPhotos($withPhotos, 1);
        $withSong = $make();
        addSong($withSong);
        $hiddenOnly = $make();
        addPhotos($hiddenOnly, 1, ['visible' => false]);
        $empty = $make();

        (include database_path('migrations/2027_04_11_000004_add_gallery_and_music_to_classic_templates.php'))->up();

        $enabled = fn (Events $e, string $type) => collect($e->fresh()->template_config['sections'] ?? [])->firstWhere('type', $type)['enabled'] ?? null;
        expect($enabled($withPhotos, 'gallery'))->toBeFalse()->and($enabled($withPhotos, 'music'))->toBeNull()
            ->and($enabled($withSong, 'music'))->toBeFalse()->and($enabled($withSong, 'gallery'))->toBeNull()
            ->and($enabled($hiddenOnly, 'gallery'))->toBeNull()
            ->and($empty->fresh()->template_config)->toBeNull();
    });
});

/** @return array{0: Events, 1: User, 2: string} */
function musicEvent(string $plan = 'premium', string $role = 'owner'): array
{
    [$event] = galleryEvent($plan);
    $user = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $user->id, 'role_id' => Role::where('name', $role)->value('id')]);

    return [$event, $user, "/api/v1/app/event/{$event->id}/background-music"];
}

function songFile(string $name = 'song.mp3', ?string $bytes = null): UploadedFile
{
    $bytes ??= "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 500);
    $tmp = tempnam(sys_get_temp_dir(), 'song');
    file_put_contents($tmp, $bytes);

    return new UploadedFile($tmp, $name, null, null, true);
}

describe('background music routes', function () {
    it('uploads, replaces (deleting the old file) and deletes one song per event', function () {
        [$event, $owner, $url] = musicEvent();

        $created = $this->actingAs($owner)->post($url, ['songFile' => songFile(), 'title' => 'Vals'], ['Accept' => 'application/json'])->assertCreated();
        expect($created->json('data.title'))->toBe('Vals')
            ->and($created->json('data.mimeType'))->toBe('audio/mpeg')
            ->and($created->json('data.sizeBytes'))->toBeGreaterThan(0)
            ->and($created->json('data.eventId'))->toBe($event->id);
        $first = BackgroundMusic::where('event_id', $event->id)->value('song_url');
        Storage::disk('public')->assertExists($first);

        $replaced = $this->actingAs($owner)->post($url, ['songFile' => songFile('otra.mp3')], ['Accept' => 'application/json'])->assertOk();
        $second = BackgroundMusic::where('event_id', $event->id)->value('song_url');
        expect(BackgroundMusic::where('event_id', $event->id)->count())->toBe(1)
            ->and($second)->not->toBe($first)
            ->and($replaced->json('data.title'))->toBe('Vals');
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        expect(Storage::disk('public')->allFiles())->toHaveCount(1);

        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('data.id', $replaced->json('data.id'));

        $this->actingAs($owner)->deleteJson($url)->assertOk()->assertExactJson(['data' => null]);
        Storage::disk('public')->assertMissing($second);
        $this->actingAs($owner)->getJson($url)->assertOk()->assertExactJson(['data' => null]);
        $this->actingAs($owner)->deleteJson($url)->assertNotFound()->assertJson(['code' => 'background_music_not_found']);
    });

    it('collapses legacy duplicate rows when replacing', function () {
        [$event, $owner, $url] = musicEvent();
        addSong($event);
        Storage::disk('public')->put('sound/background-music/' . $event->id . '/old2.mp3', 'x');
        BackgroundMusic::create(['event_id' => $event->id, 'song_url' => 'sound/background-music/' . $event->id . '/old2.mp3']);

        $this->actingAs($owner)->post($url, ['songFile' => songFile()], ['Accept' => 'application/json'])->assertOk();

        expect(BackgroundMusic::where('event_id', $event->id)->count())->toBe(1)->and(Storage::disk('public')->allFiles())->toHaveCount(1);
    });

    it('accepts m4a and ogg by content', function (string $name, string $bytes) {
        [, $owner, $url] = musicEvent();

        $this->actingAs($owner)->post($url, ['songFile' => songFile($name, $bytes)], ['Accept' => 'application/json'])->assertSuccessful();
    })->with([
        ['song.m4a', "\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00M4A mp42isom\x00\x00\x00\x00" . str_repeat("\x00", 200)],
        ['song.ogg', "OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x1e\x01vorbis" . str_repeat("\x00", 40)],
    ]);

    it('accepts the mp4 box of an Android m4a/aac only under those extensions, and refuses it elsewhere', function (string $name, bool $accepted) {
        [, $owner, $url] = musicEvent();
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 200);

        $response = $this->actingAs($owner)->post($url, ['songFile' => songFile($name, $bytes)], ['Accept' => 'application/json']);

        $accepted
            ? $response->assertSuccessful()
            : $response->assertStatus(422)->assertJsonPath('errors.songFile.0', 'invalid_type');
    })->with([
        'm4a' => ['android.m4a', true],
        'aac' => ['android.aac', true],
        'mp3' => ['android.mp3', false],
        'ogg' => ['android.ogg', false],
        'mp4' => ['clip.mp4', false],
    ]);

    it('stores an Android m4a/aac under its own extension and reports its mime type', function (string $name, string $mime) {
        [$event, $owner, $url] = musicEvent();
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 200);

        $response = $this->actingAs($owner)->post($url, ['songFile' => songFile($name, $bytes)], ['Accept' => 'application/json'])->assertSuccessful();

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        expect(BackgroundMusic::where('event_id', $event->id)->value('song_url'))->toEndWith('.' . $ext)
            ->and($response->json('data.mimeType'))->toBe($mime);
    })->with([
        'm4a' => ['android.m4a', 'audio/mp4'],
        'aac' => ['android.aac', 'audio/aac'],
    ]);

    it('refuses wav, a wrong extension and fake audio with invalid_type', function (string $name, string $bytes) {
        [, $owner, $url] = musicEvent();

        $response = $this->actingAs($owner)->post($url, ['songFile' => songFile($name, $bytes)], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonPath('errors.songFile.0', 'invalid_type');
        expect(Storage::disk('public')->allFiles())->toBeEmpty();
    })->with([
        'wav' => ['song.wav', "RIFF\x24\x08\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xAC\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x08\x00\x00" . "\x00\x00"],
        'wav named mp3' => ['song.mp3', "RIFF\x24\x08\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xAC\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x08\x00\x00" . "\x00\x00"],
        'mp3 named txt' => ['song.txt', "ID3\x03\x00\x00\x00\x00\x00\x00" . "\xFF\xFB\x90\x00"],
        'html' => ['song.mp3', '<html><script>alert(1)</script></html>'],
        'svg' => ['song.mp3', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'php' => ['song.mp3', '<?php system($_GET["c"]);'],
    ]);

    it('asks for the file, caps it at 10 MB and caps the title at 60 characters', function () {
        [, $owner, $url] = musicEvent();
        $post = fn (array $body) => $this->actingAs($owner)->post($url, $body, ['Accept' => 'application/json']);

        $post([])->assertStatus(422)->assertJsonPath('errors.songFile.0', 'required');
        $post(['songFile' => songFile('big.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 3_000_000))])
            ->assertStatus(422)->assertJsonPath('errors.songFile.0', 'too_large');
        $post(['songFile' => songFile(), 'title' => str_repeat('a', 61)])->assertStatus(422)->assertJsonPath('errors.title.0', 'too_long');
    });

    it('locks upload and delete on the basic plan with module_locked, but still lets the organizer read', function () {
        [, $owner, $url] = musicEvent('basic');

        $this->actingAs($owner)->post($url, ['songFile' => songFile()], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJson(['code' => 'module_locked', 'module' => 'background_music']);
        $this->actingAs($owner)->deleteJson($url)->assertForbidden()->assertJson(['code' => 'module_locked']);
        $this->actingAs($owner)->getJson($url)->assertOk();
    });

    it('lets a viewer read but not change the song, and a stranger see nothing', function () {
        [$event, $viewer, $url] = musicEvent('premium', 'viewer');

        $this->actingAs($viewer)->getJson($url)->assertOk();
        $this->actingAs($viewer)->post($url, ['songFile' => songFile()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->post($url, ['songFile' => songFile()], ['Accept' => 'application/json'])->assertNotFound();
    });

    it('limits uploads with its own named limiter', function () {
        expect(RouteServiceProvider::ROUTE_LIMITS['background-music-upload'])->toBe([10, 1]);
        [, $owner, $url] = musicEvent();

        foreach (range(1, 10) as $i) {
            $this->actingAs($owner)->post($url, [], ['Accept' => 'application/json'])->assertStatus(422);
        }
        $this->actingAs($owner)->post($url, [], ['Accept' => 'application/json'])->assertStatus(429);
        // GET is not counted.
        $this->actingAs($owner)->getJson($url)->assertOk();
    });
});

describe('RSVP note and companion names', function () {
    function rsvpPost($test, array $body)
    {
        return $test->postJson(GM_INVITATION . '/rsvp', array_merge(['guest' => ['rsvpStatus' => 'attending']], $body));
    }

    it('saves the note and the companion names, and clears the note with empty or null', function () {
        [$event, $main, $companions] = galleryEvent();
        $first = $companions[0];

        $response = rsvpPost($this, [
            'companions' => [['id' => $first->id, 'rsvpStatus' => 'attending', 'name' => '  Ana López  ']],
            'note' => "  Llegamos tarde\npor el vuelo  ",
        ])->assertOk();

        expect($response->json('data.note'))->toBe("Llegamos tarde\npor el vuelo")
            ->and($response->json('data.canEditNames'))->toBeTrue()
            ->and($response->json('data.companions.0.name'))->toBe('Ana López')
            ->and($main->fresh()->rsvp_note_at)->not->toBeNull()
            ->and($companions[1]->fresh()->rsvp_note)->toBeNull();

        // Without the key nothing changes.
        rsvpPost($this, [])->assertOk()->assertJsonPath('data.note', "Llegamos tarde\npor el vuelo");

        rsvpPost($this, ['note' => ''])->assertOk()->assertJsonPath('data.note', null);
        expect($main->fresh()->rsvp_note)->toBeNull()->and($main->fresh()->rsvp_note_at)->toBeNull();

        rsvpPost($this, ['note' => 'otra'])->assertOk();
        rsvpPost($this, ['note' => null])->assertOk()->assertJsonPath('data.note', null);
    });

    it('stores the note and names as text, never interpreting html', function () {
        [, $main] = galleryEvent();

        rsvpPost($this, ['note' => '<script>alert(1)</script> https://x.test'])->assertOk()
            ->assertJsonPath('data.note', '<script>alert(1)</script> https://x.test');
        expect($main->fresh()->rsvp_note)->toBe('<script>alert(1)</script> https://x.test');
    });

    it('rejects a note over 500 characters and accepts exactly 500', function () {
        galleryEvent();

        rsvpPost($this, ['note' => str_repeat('ñ', 501)])->assertStatus(422)->assertJsonPath('errors.note.0', 'too_long');
        rsvpPost($this, ['note' => str_repeat('ñ', 500)])->assertOk();
    });

    it('validates companion names with codes', function (mixed $name, string $code) {
        [, , $companions] = galleryEvent();

        rsvpPost($this, ['companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'name' => $name]]])
            ->assertStatus(422)->assertJsonPath('errors', fn ($errors) => ($errors['companions.0.name'][0] ?? null) === $code);
    })->with([
        'empty' => ['', 'required'],
        'one letter' => ['A', 'too_short'],
        '121 letters' => [str_repeat('a', 121), 'too_long'],
        'control character' => ["Ana\x07 López", 'invalid_characters'],
        'newline' => ["Ana\nLópez", 'invalid_characters'],
        'right-to-left override' => ["Ana\u{202E}López", 'invalid_characters'],
        'isolate' => ["Ana\u{2066}López", 'invalid_characters'],
        'direction mark' => ["Ana\u{200F}López", 'invalid_characters'],
        'zero-width space' => ["Ana\u{200B}López", 'invalid_characters'],
        'zero-width non-joiner' => ["Ana\u{200C}López", 'invalid_characters'],
        'zero-width joiner' => ["Ana\u{200D}López", 'invalid_characters'],
        'word joiner' => ["Ana\u{2060}López", 'invalid_characters'],
        'byte order mark' => ["Ana\u{FEFF}López", 'invalid_characters'],
    ]);

    it('refuses direction and control characters in the note but keeps line breaks and accents', function (string $note, bool $valid) {
        galleryEvent();

        $response = rsvpPost($this, ['note' => $note]);
        $valid ? $response->assertOk() : $response->assertStatus(422)->assertJsonPath('errors.note.0', 'invalid_characters');
    })->with([
        'line breaks' => ["Hola\r\nllegamos tarde ñ 🎉", true],
        'bell' => ["Hola\x07", false],
        'escape' => ["Hola\x1B[31m", false],
        'tab' => ["Hola\tmundo", false],
        'rlo' => ["Hola \u{202E}evol", false],
        'lri' => ["Hola \u{2066}x", false],
        'rlm' => ["Hola\u{200F}mundo", false],
        'nul' => ["Hola\0mundo", false],
        'zero-width space' => ["Hola\u{200B}mundo", false],
        'zero-width joiner' => ["Hola\u{200D}mundo", false],
        'word joiner' => ["Hola\u{2060}mundo", false],
        'byte order mark' => ["Hola\u{FEFF}mundo", false],
    ]);

    it('accepts names of 2 and 120 characters', function () {
        [, , $companions] = galleryEvent();

        rsvpPost($this, ['companions' => [
            ['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'name' => 'Al'],
            ['id' => $companions[1]->id, 'rsvpStatus' => 'attending', 'name' => str_repeat('a', 120)],
        ]])->assertOk();
    });

    it('does not let a guest rename or answer for another family’s companion', function () {
        [$event] = galleryEvent();
        $other = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => 'OTH999']);
        $stranger = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $other->id, 'name' => 'Ajeno']);

        rsvpPost($this, ['companions' => [['id' => $stranger->id, 'rsvpStatus' => 'attending', 'name' => 'Hackeado']]])
            ->assertStatus(422)->assertJsonPath('errors', fn ($errors) => ($errors['companions'][0] ?? null) === 'invalid');
        expect($stranger->fresh()->name)->toBe('Ajeno');

        // The id itself is validated as a code too.
        rsvpPost($this, ['companions' => [['rsvpStatus' => 'attending', 'name' => 'Sin id']]])
            ->assertStatus(422)->assertJsonPath('errors', fn ($errors) => ($errors['companions.0.id'][0] ?? null) === 'invalid');
    });

    it('closes the door on note and names when the deadline passed', function () {
        [$event, $main, $companions] = galleryEvent();
        DB::table('rsvps')->insert([
            'event_id' => $event->id, 'title' => 'RSVP', 'description' => '', 'custom_fields' => json_encode([]),
            'confirmation_deadline' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        rsvpPost($this, ['note' => 'tarde', 'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'name' => 'Nueva']]])
            ->assertStatus(422)->assertJsonPath('errors.rsvp.0', 'closed');
        expect($main->fresh()->rsvp_note)->toBeNull()->and($companions[0]->fresh()->name)->not->toBe('Nueva');

        $show = $this->getJson(GM_INVITATION)->assertOk();
        expect(sectionOf($show, 'rsvp')['data']['canEditNames'])->toBeFalse();
    });

    it('writes no status log for a change of name or note alone', function () {
        [, $main, $companions] = galleryEvent();
        rsvpPost($this, ['companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending']]])->assertOk();
        $logs = GuestRsvpLog::count();
        $date = $main->fresh()->rsvp_status_date;

        rsvpPost($this, [
            'note' => 'hola',
            'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'name' => 'Nombre nuevo']],
        ])->assertOk();

        expect(GuestRsvpLog::count())->toBe($logs)
            ->and($main->fresh()->rsvp_status_date->equalTo($date))->toBeTrue()
            ->and($companions[0]->fresh()->name)->toBe('Nombre nuevo');
    });

    it('declining still saves the names and the note', function () {
        [, $main, $companions] = galleryEvent();

        rsvpPost($this, [
            'guest' => ['rsvpStatus' => 'not-attending'], 'note' => 'No podemos',
            'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'name' => 'Ana']],
        ])->assertOk();

        expect($companions[0]->fresh()->name)->toBe('Ana')->and($companions[0]->fresh()->rsvp_status)->toBe('not-attending')
            ->and($main->fresh()->rsvp_note)->toBe('No podemos');
    });

    it('shows each guest only their own note, never the organizer’s notes or another family’s', function () {
        [$event, $main] = galleryEvent();
        $main->forceFill(['notes' => 'Nota privada de la organizadora', 'rsvp_note' => 'Mi mensaje'])->save();
        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => 'OTH999'])->forceFill(['rsvp_note' => 'Mensaje ajeno'])->save();

        $mine = $this->getJson(GM_INVITATION)->assertOk();
        $theirs = $this->getJson('/api/v1/app/invitation/xv-galeria/OTH999')->assertOk();

        expect(sectionOf($mine, 'rsvp')['data']['note'])->toBe('Mi mensaje')
            ->and($mine->getContent())->not->toContain('Mensaje ajeno')->not->toContain('Nota privada')
            ->and(sectionOf($theirs, 'rsvp')['data']['note'])->toBe('Mensaje ajeno')
            ->and($theirs->getContent())->not->toContain('Mi mensaje');
    });

    it('limits answers per link (slug and code) with 429 too_many_attempts', function () {
        galleryEvent();

        foreach (range(1, 10) as $i) {
            $this->postJson(GM_INVITATION . '/rsvp', [])->assertStatus(422);
        }
        $this->postJson(GM_INVITATION . '/rsvp', [])->assertStatus(429)->assertJson(['code' => 'too_many_attempts']);

        // Another link from the same address is not blocked by this counter.
        $this->postJson('/api/v1/app/invitation/xv-galeria/OTHER1/rsvp', [])->assertStatus(404);
    });

    it('shows the note in the roster to every role', function (string $role) {
        [$event, $user, $url] = musicEvent('premium', $role);
        $main = Guest::where('event_id', $event->id)->whereNull('parent_id')->first();
        $main->forceFill(['rsvp_note' => 'Vamos con ganas', 'rsvp_note_at' => now()])->save();

        $row = $this->actingAs($user)->getJson("/api/v1/app/event/{$event->id}/roster")->assertOk()->json('data.0');

        expect($row['rsvpNote'])->toBe('Vamos con ganas')->and($row['rsvpNoteAt'])->toBeString()
            ->and($row['notes'])->not->toBe('Vamos con ganas');
    })->with(['owner', 'editor', 'viewer']);
});
