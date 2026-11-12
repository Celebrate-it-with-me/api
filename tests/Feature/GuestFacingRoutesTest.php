<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\SuggestedMusic;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function publicEvent(string $status = 'published'): Events
{
    return Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
        'status' => $status,
    ]);
}

function guestOf(Events $event, string $code): Guest
{
    return Guest::factory()->create(['event_id' => $event->id, 'code' => $code]);
}

describe('reading', function () {
    it('needs a valid access code of this event', function (string $path) {
        $event = publicEvent();
        guestOf($event, 'VAL001');
        $other = publicEvent();
        guestOf($other, 'OTH001');
        $api = "/api/v1/app/template/event/{$event->id}/{$path}";

        $this->getJson($api)->assertNotFound();                                   // no code
        $this->getJson("$api?accessCode=NOPE99")->assertNotFound();               // unknown code
        $this->getJson("$api?accessCode=OTH001")->assertNotFound();               // code of another event
        $this->getJson("$api?accessCode=VAL001")->assertOk();                     // the right one
    })->with(['comments', 'suggest-music']);

    it('answers the same 404 for a draft, a missing event and a wrong code', function () {
        $draft = publicEvent('draft');
        guestOf($draft, 'DRA001');

        $this->getJson("/api/v1/app/template/event/{$draft->id}/comments?accessCode=DRA001")->assertNotFound();
        $this->getJson('/api/v1/app/template/event/999999/comments?accessCode=DRA001')->assertNotFound();
    });
});

describe('writing', function () {
    it('cannot comment with the code of a guest of another event', function () {
        $event = publicEvent();
        $other = publicEvent();
        guestOf($other, 'OTH001');

        $this->postJson("/api/v1/app/template/event/{$event->id}/comments", ['accessCode' => 'OTH001', 'comment' => 'hola'])
            ->assertNotFound();
    });

    it('lets a guest delete their own song even if another event reuses the same code', function () {
        $other = publicEvent();
        guestOf($other, 'SAME01');          // created first: it used to win the code lookup
        $event = publicEvent();
        $author = guestOf($event, 'SAME01');
        $song = SuggestedMusic::create([
            'event_id' => $event->id, 'title' => 'Vals', 'artist' => 'X', 'album' => 'Y', 'thumbnailUrl' => 'https://x.test/t.jpg', 'previewUrl' => 'https://x.test/p.mp3', 'platform' => 'spotify', 'platformId' => 'abc',
            'suggested_by_entity' => 'guest', 'suggested_by_id' => $author->id,
        ]);

        $this->deleteJson("/api/v1/app/template/event/{$event->id}/suggest-music", ['accessCode' => 'SAME01', 'songId' => $song->id])
            ->assertOk();
        expect(SuggestedMusic::find($song->id))->toBeNull();
    });

    it('does not let a guest delete a song suggested by someone else', function () {
        $event = publicEvent();
        $author = guestOf($event, 'AUT001');
        guestOf($event, 'INT001');
        $song = SuggestedMusic::create([
            'event_id' => $event->id, 'title' => 'Vals', 'artist' => 'X', 'album' => 'Y', 'thumbnailUrl' => 'https://x.test/t.jpg', 'previewUrl' => 'https://x.test/p.mp3', 'platform' => 'spotify', 'platformId' => 'abc',
            'suggested_by_entity' => 'guest', 'suggested_by_id' => $author->id,
        ]);

        $this->deleteJson("/api/v1/app/template/event/{$event->id}/suggest-music", ['accessCode' => 'INT001', 'songId' => $song->id])
            ->assertForbidden();
        expect(SuggestedMusic::find($song->id))->not->toBeNull();
    });
});
