<?php

use App\Http\Services\WhatsApp\DailyLimit;
use App\Models\EventUserRole;
use App\Models\WhatsApp\Message;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function dlMessage($event, $guest, ?string $queuedAt, string $type = 'template'): Message
{
    return Message::create([
        'event_id' => $event->id, 'guest_id' => $guest->id, 'template_key' => 'invitation', 'locale' => 'es',
        'to_phone' => '+13055550123', 'status' => 'queued', 'type' => $type, 'queued_at' => $queuedAt,
    ]);
}

it('takes the configured event cap, even a zero, and falls back to 3 per guest with a phone (min 100)', function () {
    [$event] = waEvent();
    $limit = new DailyLimit;

    config(['services.whatsapp.max_daily_per_event' => 7]);
    expect($limit->eventLimit($event))->toBe(7);

    config(['services.whatsapp.max_daily_per_event' => '0']);
    expect($limit->eventLimit($event))->toBe(0);

    config(['services.whatsapp.max_daily_per_event' => '']);
    expect($limit->eventLimit($event))->toBe(100);

    // 40 guests with a phone = 120; companions (parent_id), empty phones and null phones do not count.
    config(['services.whatsapp.max_daily_per_event' => null]);
    foreach (range(1, 40) as $i) {
        waGuest($event);
    }
    $main = waGuest($event);
    waGuest($event, ['parent_id' => $main->id]);
    waGuest($event, ['phone' => '']);
    waGuest($event, ['phone' => null]);
    expect($limit->eventLimit($event))->toBe(3 * 41);
});

it('reads the account cap from config with a default of 1000', function () {
    config(['services.whatsapp.max_daily_per_account' => 1000]);
    expect((new DailyLimit)->accountLimit())->toBe(1000);
    // No key at all (config not published): the default in the class applies.
    $services = config('services.whatsapp');
    unset($services['max_daily_per_account']);
    config(['services.whatsapp' => $services]);
    expect((new DailyLimit)->accountLimit())->toBe(1000);
    config(['services.whatsapp.max_daily_per_account' => '25']);
    expect((new DailyLimit)->accountLimit())->toBe(25);
});

it('counts only template messages of the last 24 hours against the event cap', function () {
    [$event] = waEvent();
    $g = waGuest($event);
    config(['services.whatsapp.max_daily_per_event' => 10, 'services.whatsapp.max_daily_per_account' => 1000]);
    $limit = new DailyLimit;
    expect($limit->remaining($event))->toBe(10);

    dlMessage($event, $g, now()->subHours(1)->toDateTimeString());
    dlMessage($event, $g, now()->subHours(23)->toDateTimeString());
    dlMessage($event, $g, now()->subHours(25)->toDateTimeString());   // left the window
    dlMessage($event, $g, now()->subHours(1)->toDateTimeString(), 'text');   // not a template
    dlMessage($event, $g, null);   // never queued
    expect($limit->remaining($event))->toBe(8);

    $retried = dlMessage($event, $g, now()->toDateTimeString());
    expect($limit->remaining($event))->toBe(7)
        ->and($limit->remaining($event, $retried->id))->toBe(8);
});

it('never goes below zero when the cap is already exceeded', function () {
    [$event] = waEvent();
    $g = waGuest($event);
    config(['services.whatsapp.max_daily_per_event' => 1]);
    dlMessage($event, $g, now()->toDateTimeString());
    dlMessage($event, $g, now()->toDateTimeString());

    expect((new DailyLimit)->remaining($event))->toBe(0);
});

it('applies the account cap across all the events of the same owner, and the tighter cap wins', function () {
    [$event, $u] = waEvent();
    [$second] = waEvent();
    [$foreign] = waEvent();
    $owner = Role::where('name', 'owner')->value('id');
    EventUserRole::where('event_id', $second->id)->where('role_id', $owner)->delete();
    // The second event belongs to the same owner as the first; the foreign one stays with its own.
    EventUserRole::create(['event_id' => $second->id, 'user_id' => $u['owner']->id, 'role_id' => $owner]);

    config(['services.whatsapp.max_daily_per_event' => 50, 'services.whatsapp.max_daily_per_account' => 5]);
    $g1 = waGuest($event);
    $g2 = waGuest($second);
    $g3 = waGuest($foreign);
    foreach ([[$event, $g1], [$event, $g1], [$second, $g2], [$foreign, $g3], [$foreign, $g3]] as [$e, $g]) {
        dlMessage($e, $g, now()->toDateTimeString());
    }

    $limit = new DailyLimit;
    // Account: 3 of 5 used by this owner (the foreign events do not count) -> 2; event: 48. The account cap wins.
    expect($limit->remaining($event))->toBe(2)->and($limit->remaining($second))->toBe(2);
});

it('counts only the event itself when it has no owner', function () {
    [$event] = waEvent();
    EventUserRole::where('event_id', $event->id)->delete();
    $g = waGuest($event);
    config(['services.whatsapp.max_daily_per_event' => 50, 'services.whatsapp.max_daily_per_account' => 4]);
    dlMessage($event, $g, now()->toDateTimeString());
    dlMessage($event, $g, now()->toDateTimeString());

    expect((new DailyLimit)->remaining($event))->toBe(2);
});

it('says when the oldest counted message frees a slot, in UTC, or null when none counts', function () {
    [$event] = waEvent();
    $g = waGuest($event);
    $limit = new DailyLimit;
    expect($limit->resetsAt($event))->toBeNull();

    dlMessage($event, $g, now()->subDays(2)->toDateTimeString());   // out of the window
    dlMessage($event, $g, now()->subHours(1)->toDateTimeString(), 'text');
    expect($limit->resetsAt($event))->toBeNull();

    $old = now()->subHours(5)->startOfSecond();
    dlMessage($event, $g, $old->toDateTimeString());
    dlMessage($event, $g, now()->subHours(1)->toDateTimeString());

    $at = $limit->resetsAt($event);
    expect($at->toDateTimeString())->toBe($old->copy()->addDay()->toDateTimeString())->and($at->timezoneName)->toBe('UTC');
});
