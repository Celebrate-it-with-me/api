<?php

use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\EventsServices;
use App\Models\EventModuleOverride;
use App\Models\Events;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function suggestionNames(Events $event): array
{
    $service = app(EventsServices::class);

    return array_column($service->getEventSuggestions($event), 'name');
}

// Before event_features was dropped the demo gave: xv-de-valentina 0.62 (plus an "View Analytics" suggestion, which
// had no module), boda-camila-y-andres 0.7 (a hand-set menu flag the basic plan locks, budget flag off) and
// xv-de-sofia with no feature suggestions (all flags off). Events created by the app had flags equal to their plan's
// core, so they do not change; only these hand-seeded rows did. The plan's modules decide now.
// Valentina then went 0.62 -> 0.54 when the demo stopped seeding suggested songs (b6d365a): suggested_music weighs 0.08 and
// is no longer completed (and its "Add Music Suggestions" suggestion now shows).
it('pins setup progress and suggestions of the demo events', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);

    $bySlug = fn (string $slug) => Events::where('custom_url_slug', $slug)->firstOrFail();

    $xv = $bySlug('xv-de-valentina');
    expect($xv->calculateSetupProgress())->toBe(0.54)
        ->and(suggestionNames($xv))->toBe(['Share Event', 'Invite Guests', 'Add Music Suggestions', 'Add Sweet Memories Images', 'Set Up Seating Arrangement', 'Set Up Budget']);

    $wedding = $bySlug('boda-camila-y-andres');
    expect($wedding->calculateSetupProgress())->toBe(0.44)
        ->and(suggestionNames($wedding))->toBe(['Share Event', 'Invite Guests', 'Set Up Budget', 'Invite Collaborators']);

    $sofia = $bySlug('xv-de-sofia');
    expect($sofia->calculateSetupProgress())->toBe(0.0)
        ->and(suggestionNames($sofia))->toBe(['Publish Event', 'Invite Guests', 'Save the Date', 'Add Location', 'Set Up RSVP', 'Set Up Budget', 'Invite Collaborators']);
});

it('counts a module in the setup progress only while it is active for the event', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);

    $wedding = Events::where('custom_url_slug', 'boda-camila-y-andres')->firstOrFail();
    $before = $wedding->calculateSetupProgress();

    // Gifting the menus module to the basic-plan event brings the (complete) menu into the progress.
    EventModuleOverride::create(['event_id' => $wedding->id, 'module_key' => 'menus', 'state' => 'active', 'reason' => 'gift']);
    app(EventModules::class)->forget();

    expect($wedding->fresh()->calculateSetupProgress())->not->toBe($before);
});
