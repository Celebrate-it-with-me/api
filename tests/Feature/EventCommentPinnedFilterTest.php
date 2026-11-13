<?php

use App\Http\Services\EventComment\EventCommentService;
use App\Models\EventComment;
use App\Models\Events;
use App\Models\Guest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pinnedFixture(): array
{
    test()->seed(DatabaseSeeder::class);
    $event = Events::factory()->published()->create();
    $guest = Guest::factory()->create(['event_id' => $event->id]);
    $make = fn (string $text, bool $pinned) => EventComment::create([
        'event_id' => $event->id, 'authorable_type' => $guest->getMorphClass(), 'authorable_id' => $guest->id,
        'comment' => $text, 'status' => 'visible', 'is_pinned' => $pinned,
    ]);

    return [$event, $make('pinned', true), $make('loose', false)];
}

it('lists every comment when there is no pinned filter', function () {
    [$event, $pinned, $loose] = pinnedFixture();

    $ids = app(EventCommentService::class)->listForEvent($event)->pluck('id')->all();

    expect($ids)->toContain($pinned->id, $loose->id);
});

it('lists only pinned comments when pinned is true', function () {
    [$event, $pinned, $loose] = pinnedFixture();

    $ids = app(EventCommentService::class)->listForEvent($event, ['pinned' => true])->pluck('id')->all();

    expect($ids)->toBe([$pinned->id]);
});

it('lists only unpinned comments when pinned is false', function () {
    [$event, $pinned, $loose] = pinnedFixture();

    $service = app(EventCommentService::class);

    expect($service->listForEvent($event, ['pinned' => false])->pluck('id')->all())->toBe([$loose->id])
        ->and($service->listForEventPaginated($event, ['pinned' => false])->pluck('id')->all())->toBe([$loose->id]);
});
