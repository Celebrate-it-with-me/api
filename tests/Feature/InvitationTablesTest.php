<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\Seating\Table;
use App\Models\Seating\TableAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

const IT_URL = '/api/v1/app/invitation/xv-mesas/TBL123';

/** @return array{0: Events, 1: Guest, 2: Guest, 3: Guest} event, main guest (code TBL123), two companions */
function tablesEvent(string $plan = 'premium'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => 'published',
        'custom_url_slug' => 'xv-mesas',
    ]);
    $main = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Rosa Fernández', 'code' => 'TBL123', 'rsvp_status' => 'pending']);
    $ana = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $main->id, 'name' => 'Ana Fernández', 'rsvp_status' => 'pending']);
    $luis = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $main->id, 'name' => 'Luis Fernández', 'rsvp_status' => 'pending']);

    return [$event, $main, $ana, $luis];
}

function seat(Events $event, Guest $guest, string $tableName, string $seat = ''): TableAssignment
{
    $table = Table::firstOrCreate(
        ['event_id' => $event->id, 'name' => $tableName],
        ['capacity' => 10, 'type' => 'family', 'priority' => 1],
    );

    return TableAssignment::create([
        'table_id' => $table->id, 'guest_id' => $guest->id, 'seat_number' => $seat,
        'assigned_at' => now(), 'assigned_by' => User::factory()->create()->id,
    ]);
}

function tablesSection($response): ?array
{
    return collect($response->json('data.sections'))->firstWhere('type', 'tables');
}

it('leaves the section out when nobody of the family has a table', function () {
    tablesEvent();

    $response = $this->getJson(IT_URL)->assertOk();

    expect(tablesSection($response))->toBeNull();
});

it('shows the table of the guest as soon as it is assigned, even with the answer still pending', function () {
    [$event, $main] = tablesEvent();
    seat($event, $main, 'Mesa 5 · Familia', '3');

    $section = tablesSection($this->getJson(IT_URL)->assertOk());

    expect($section['data'])->toBe([
        'tables' => [['personId' => $main->id, 'name' => 'Rosa Fernández', 'table' => 'Mesa 5 · Familia', 'seat' => '3']],
        'sameTable' => true,
    ])->and($section['type'])->toBe('tables');
});

it('sends a null seat when the assignment has none', function () {
    [$event, $main] = tablesEvent();
    seat($event, $main, 'Mesa 1');

    expect(tablesSection($this->getJson(IT_URL))['data']['tables'][0]['seat'])->toBeNull();
});

it('lists the companions who attend, each with their own table, and ignores the others', function () {
    [$event, $main, $ana, $luis] = tablesEvent();
    $ana->forceFill(['rsvp_status' => 'attending'])->save();
    $luis->forceFill(['rsvp_status' => 'not-attending'])->save();
    seat($event, $main, 'Mesa 1');
    seat($event, $ana, 'Mesa 2', '4');
    seat($event, $luis, 'Mesa 9');

    $data = tablesSection($this->getJson(IT_URL))['data'];

    expect(collect($data['tables'])->pluck('table')->all())->toBe(['Mesa 1', 'Mesa 2'])
        ->and(collect($data['tables'])->pluck('name')->all())->toBe(['Rosa Fernández', 'Ana Fernández'])
        ->and($data['sameTable'])->toBeFalse()
        ->and(json_encode($data))->not->toContain('Mesa 9')->not->toContain('Luis');
});

it('says sameTable when everybody listed shares it', function () {
    [$event, $main, $ana] = tablesEvent();
    $ana->forceFill(['rsvp_status' => 'attending'])->save();
    seat($event, $main, 'Mesa 7');
    seat($event, $ana, 'Mesa 7');

    $data = tablesSection($this->getJson(IT_URL))['data'];

    expect($data['tables'])->toHaveCount(2)->and($data['sameTable'])->toBeTrue();
});

it('lists only the people who have a table', function () {
    [$event, $main, $ana] = tablesEvent();
    $ana->forceFill(['rsvp_status' => 'attending'])->save();
    seat($event, $ana, 'Mesa 3');

    $data = tablesSection($this->getJson(IT_URL))['data'];

    expect(collect($data['tables'])->pluck('name')->all())->toBe(['Ana Fernández']);
});

it('never shows the table of another family, nor who else sits at it, and does not leak through the shared cache', function () {
    [$event, $main] = tablesEvent();
    $other = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Tía Marta', 'code' => 'OTH999']);
    seat($event, $main, 'Mesa 1');
    seat($event, $other, 'Mesa 1');

    $mine = $this->getJson(IT_URL)->assertOk();
    $theirs = $this->getJson('/api/v1/app/invitation/xv-mesas/OTH999')->assertOk();

    expect(json_encode(tablesSection($mine)))->not->toContain('Marta')
        ->and(json_encode(tablesSection($theirs)))->not->toContain('Rosa');

    // A family without a table opens the same cached event after the others did.
    $third = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => 'THR777']);
    expect(tablesSection($this->getJson('/api/v1/app/invitation/xv-mesas/THR777')->assertOk()))->toBeNull();
});

it('is left out on a plan without the seating module', function () {
    [$event, $main] = tablesEvent('basic');
    seat($event, $main, 'Mesa 1');

    expect(tablesSection($this->getJson(IT_URL)->assertOk()))->toBeNull();
});

it('changes the ETag of the guest when the table changes, and answers 304 while it does not', function () {
    [$event, $main] = tablesEvent();
    $assignment = seat($event, $main, 'Mesa 1');

    $first = $this->getJson(IT_URL)->assertOk();
    $etag = $first->headers->get('ETag');
    $this->getJson(IT_URL, ['If-None-Match' => $etag])->assertStatus(304);

    $table = Table::create(['event_id' => $event->id, 'name' => 'Mesa 2', 'capacity' => 10, 'type' => 'family', 'priority' => 1]);
    $assignment->forceFill(['table_id' => $table->id])->save();

    $second = $this->getJson(IT_URL, ['If-None-Match' => $etag])->assertOk();
    expect($second->headers->get('ETag'))->not->toBe($etag)
        ->and(tablesSection($second)['data']['tables'][0]['table'])->toBe('Mesa 2');

    // And a table that is renamed by the organizer shows at once.
    Table::whereKey($table->id)->first()->forceFill(['name' => 'Mesa de honor'])->save();
    expect(tablesSection($this->getJson(IT_URL))['data']['tables'][0]['table'])->toBe('Mesa de honor');
});

it('can be switched off by the organizer without losing the assignment', function () {
    [$event, $main] = tablesEvent();
    seat($event, $main, 'Mesa 1');
    $event->forceFill(['template_config' => ['sections' => [['type' => 'tables', 'enabled' => false]]]])->save();

    expect(tablesSection($this->getJson(IT_URL)->assertOk()))->toBeNull();
});
