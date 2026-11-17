<?php

use App\Http\Services\AppServices\SuggestedMusicServices;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\SuggestedMusic;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function suggestAsGuest(Events $event, string $code, int $n): SuggestedMusic
{
    $request = Request::create('/', 'POST', [
        'title' => "Song {$n}", 'artist' => 'Artist', 'album' => 'Album', 'platformId' => "track{$n}",
        'thumbnailUrl' => 'https://i.scdn.co/image/x', 'accessCode' => $code,
    ]);

    return (new SuggestedMusicServices($request))->createByGuest($event, $code);
}

/** Bug confirmed by Atlas: `suggested_by_entity` is saved as the morph alias `guest` but the cap counted `Guest::class`. */
it('enforces the cap of suggestions per guest', function () {
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'status' => 'published',
    ]);
    $guest = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => 'CAP123']);

    foreach (range(1, 5) as $i) {
        suggestAsGuest($event, 'CAP123', $i);
    }

    expect(fn () => suggestAsGuest($event, 'CAP123', 6))->toThrow(ValidationException::class)
        ->and(SuggestedMusic::where('suggested_by_id', $guest->id)->count())->toBe(5)
        ->and(SuggestedMusic::first()->suggested_by_entity)->toBe('guest');
});
