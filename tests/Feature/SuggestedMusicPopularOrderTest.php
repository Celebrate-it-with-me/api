<?php

use App\Models\Events;
use App\Models\Guest;
use App\Models\SuggestedMusic;
use App\Models\SuggestedMusicVote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('orders suggested music by net score without failing on PostgreSQL', function () {
    $event = Events::factory()->create();
    $mk = fn (string $t) => SuggestedMusic::create([
        'event_id' => $event->id, 'title' => $t, 'artist' => 'A', 'album' => 'B', 'platformId' => $t,
        'platform' => 'spotify', 'thumbnailUrl' => 'https://x/y.png', 'suggested_by_entity' => 'user', 'suggested_by_id' => 1,
    ]);
    $low = $mk('low');
    $high = $mk('high');
    $guests = Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => null]);
    foreach ($guests as $g) {
        SuggestedMusicVote::create(['suggested_music_id' => $high->id, 'guest_id' => $g->id, 'vote_type' => 'up']);
    }
    SuggestedMusicVote::create(['suggested_music_id' => $low->id, 'guest_id' => $guests[0]->id, 'vote_type' => 'down']);

    $titles = SuggestedMusic::query()->where('event_id', $event->id)->popular()->pluck('title')->all();

    expect($titles)->toBe(['high', 'low']);
});
