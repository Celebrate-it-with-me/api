<?php

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

function musicUpload(string $name, string $bytes, string $mime = 'audio/mpeg'): array
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
    ]);
    $owner = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $bytes);

    return [$owner, "/api/v1/app/event/{$event->id}/background-music", new UploadedFile($tmp, $name, $mime, null, true)];
}

function musicBody(UploadedFile $file): array
{
    return ['iconSize' => 'md', 'iconPosition' => 'br', 'iconColor' => '#fff', 'autoplay' => false, 'songFile' => $file];
}

it('refuses html, svg and php uploaded as background music (S-13)', function (string $name, string $bytes) {
    [$owner, $url, $file] = musicUpload($name, $bytes);

    $this->actingAs($owner)->post($url, musicBody($file), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('songFile');
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
})->with([
    ['song.mp3', '<html><script>alert(1)</script></html>'],
    ['song.mp3', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    ['song.mp3', '<?php system($_GET["c"]);'],
]);

it('refuses a song over 10 MB', function () {
    [$owner, $url, $file] = musicUpload('song.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 3_000_000));

    $this->actingAs($owner)->post($url, musicBody($file), ['Accept' => 'application/json'])->assertStatus(422);
});

it('accepts a real mp3', function () {
    [$owner, $url, $file] = musicUpload('song.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 500));

    $this->actingAs($owner)->post($url, musicBody($file), ['Accept' => 'application/json'])->assertSuccessful();
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('keeps the previous title when a replacement comes without a title, and clears it when the title comes empty', function () {
    [$owner, $url, $file] = musicUpload('song.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 500));
    $this->actingAs($owner)->post($url, musicBody($file) + ['title' => 'Mi vals'], ['Accept' => 'application/json'])->assertCreated();

    [, , $second] = musicUpload('b.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 500));
    $this->actingAs($owner)->post($url, musicBody($second), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.title', 'Mi vals');

    [, , $third] = musicUpload('c.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x00", 500));
    $this->actingAs($owner)->post($url, musicBody($third) + ['title' => ''], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.title', null);
});

it('refuses a video renamed .m4a, and still accepts an audio-only mp4 box', function () {
    $head = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
    $video = $head . "\x00\x00\x00\x21hdlr\x00\x00\x00\x00\x00\x00\x00\x00vide" . str_repeat("\x00", 200);
    $audio = $head . "\x00\x00\x00\x21hdlr\x00\x00\x00\x00\x00\x00\x00\x00soun" . str_repeat("\x00", 200);

    [$owner, $url, $file] = musicUpload('clip.m4a', $video, 'audio/mp4');
    $this->actingAs($owner)->post($url, musicBody($file), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.songFile.0', 'invalid_type');

    [, , $ok] = musicUpload('voice.m4a', $audio, 'audio/mp4');
    $this->actingAs($owner)->post($url, musicBody($ok), ['Accept' => 'application/json'])->assertSuccessful();
});

it('answers the missing-song delete in the language of the request', function () {
    [$owner, $url] = musicUpload('x.mp3', 'x');

    $this->actingAs($owner)->deleteJson($url, [], ['X-Locale' => 'es'])->assertNotFound()->assertJsonPath('message', 'Este evento no tiene música de fondo.')->assertJsonPath('code', 'background_music_not_found');
    $this->actingAs($owner)->deleteJson($url, [], ['X-Locale' => 'en'])->assertNotFound()->assertJsonPath('message', 'This event has no background music.');
});
