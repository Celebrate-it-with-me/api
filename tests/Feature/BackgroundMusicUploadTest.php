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
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
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
