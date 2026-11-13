<?php

use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Seating\Table;
use App\Models\Seating\TableAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function seatingEvent(): array
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
    ]);
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}"];
}

function tableData(array $extra = []): array
{
    return array_merge(['name' => 'Mesa 1', 'capacity' => 2, 'type' => 'family', 'priority' => 1], $extra);
}

function makeTable($test, $owner, string $api, array $extra = []): int
{
    return $test->actingAs($owner)->postJson("$api/tables", tableData($extra))->assertCreated()->json('data.id');
}

it('creates, lists, edits and deletes tables', function () {
    [, $u, $api] = seatingEvent();
    $id = makeTable($this, $u['owner'], $api);

    $this->actingAs($u['owner'])->putJson("$api/tables/$id", tableData(['name' => 'Mesa de honor', 'capacity' => 4]))->assertOk()->assertJsonPath('data.name', 'Mesa de honor');
    $list = $this->actingAs($u['owner'])->getJson("$api/tables")->assertOk();
    expect($list->json('data'))->toHaveCount(1)->and($list->json('meta'))->toMatchArray(['total_tables' => 1, 'total_capacity' => 4]);

    $this->actingAs($u['owner'])->deleteJson("$api/tables/$id")->assertOk();
    expect(Table::count())->toBe(0);
});

it('validates a table', function () {
    [, $u, $api] = seatingEvent();
    $post = fn (array $p) => $this->actingAs($u['owner'])->postJson("$api/tables", $p);

    $post(tableData(['name' => '']))->assertJsonValidationErrors('name');
    $post(tableData(['capacity' => 0]))->assertJsonValidationErrors('capacity');
    $post(tableData(['type' => 'royal']))->assertJsonValidationErrors('type');
});

it('seats a guest, refuses a full table and a guest already seated', function () {
    [$event, $u, $api] = seatingEvent();
    $table = makeTable($this, $u['owner'], $api, ['capacity' => 1]);
    $other = makeTable($this, $u['owner'], $api, ['name' => 'Mesa 2', 'capacity' => 5]);
    [$a, $b] = Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => null]);

    $this->actingAs($u['owner'])->postJson("$api/tables/$table/assign", ['guest_id' => $a->id])->assertOk();
    $this->actingAs($u['owner'])->postJson("$api/tables/$table/assign", ['guest_id' => $b->id])->assertStatus(422);
    $this->actingAs($u['owner'])->postJson("$api/tables/$other/assign", ['guest_id' => $a->id])->assertStatus(422);
    expect(TableAssignment::count())->toBe(1);
});

it('does not seat a guest of another event', function () {
    [, $u, $api] = seatingEvent();
    [$otherEvent] = seatingEvent();
    $table = makeTable($this, $u['owner'], $api);
    $foreign = Guest::factory()->create(['event_id' => $otherEvent->id, 'parent_id' => null]);

    $this->actingAs($u['owner'])->postJson("$api/tables/$table/assign", ['guest_id' => $foreign->id])->assertStatus(422);
    expect(TableAssignment::count())->toBe(0);
});

it('bulk-assigns only guests and tables of this event', function () {
    [$event, $u, $api] = seatingEvent();
    [$otherEvent, $u2, $api2] = seatingEvent();
    $table = makeTable($this, $u['owner'], $api, ['capacity' => 5]);
    $foreignTable = makeTable($this, $u2['owner'], $api2);
    [$mine, $mine2] = Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => null]);
    $foreignGuest = Guest::factory()->create(['event_id' => $otherEvent->id, 'parent_id' => null]);
    $bulk = fn (array $a) => $this->actingAs($u['owner'])->postJson("$api/seating/bulk-assign", ['assignments' => $a]);

    $bulk([['table_id' => $table, 'guest_ids' => [$mine->id, $mine2->id]]])->assertOk()->assertJsonPath('meta.total_assigned', 2);
    $bulk([['table_id' => $table, 'guest_ids' => [$foreignGuest->id]]])->assertStatus(422)->assertJsonValidationErrors('assignments.0.guest_ids.0');
    $bulk([['table_id' => $foreignTable, 'guest_ids' => [$mine->id]]])->assertStatus(422)->assertJsonValidationErrors('assignments.0.table_id');
    expect(TableAssignment::count())->toBe(2);
});

it('lists unassigned guests lightly, without a QR code per guest', function () {
    [$event, $u, $api] = seatingEvent();
    Guest::factory()->count(3)->create(['event_id' => $event->id, 'parent_id' => null]);

    $response = $this->actingAs($u['owner'])->getJson("$api/seating/unassigned")->assertOk();

    expect($response->json('data'))->toHaveCount(3)->and($response->json('meta.total_unassigned'))->toBe(3);
    expect($response->getContent())->not->toContain('invitationQR')->not->toContain('invitationUrl')->not->toContain('menuSelected');
    expect($response->json('data.0'))->toHaveKeys(['id', 'name', 'rsvpStatus', 'parentId']);
});

it('shows the guests of a table lightly too', function () {
    [$event, $u, $api] = seatingEvent();
    $table = makeTable($this, $u['owner'], $api);
    $guest = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null]);
    $this->actingAs($u['owner'])->postJson("$api/tables/$table/assign", ['guest_id' => $guest->id])->assertOk();

    $response = $this->actingAs($u['owner'])->getJson("$api/tables/$table")->assertOk();

    expect($response->json('data.assigned_guests.0.name'))->toBe($guest->name);
    expect($response->getContent())->not->toContain('invitationQR');
});

it('removes a guest from a table and clears all seating', function () {
    [$event, $u, $api] = seatingEvent();
    $table = makeTable($this, $u['owner'], $api, ['capacity' => 5]);
    [$a, $b] = Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => null]);
    $this->actingAs($u['owner'])->postJson("$api/tables/$table/assign", ['guest_id' => $a->id]);
    $this->actingAs($u['owner'])->postJson("$api/tables/$table/assign", ['guest_id' => $b->id]);

    $this->actingAs($u['owner'])->deleteJson("$api/tables/$table/guest/{$a->id}")->assertOk();
    $this->actingAs($u['owner'])->deleteJson("$api/seating/clear-all")->assertOk();
    expect(TableAssignment::count())->toBe(0);
});

it('keeps viewers read-only and strangers and other events out', function () {
    [, $u, $api] = seatingEvent();
    [, $u2, $api2] = seatingEvent();
    $table = makeTable($this, $u['owner'], $api);
    $foreign = makeTable($this, $u2['owner'], $api2);

    $this->actingAs($u['viewer'])->getJson("$api/tables")->assertOk();
    $this->actingAs($u['viewer'])->postJson("$api/tables", tableData())->assertForbidden();
    $this->actingAs($u['viewer'])->deleteJson("$api/tables/$table")->assertForbidden();
    $this->actingAs($u['viewer'])->deleteJson("$api/seating/clear-all")->assertForbidden();
    $this->actingAs($u['stranger'])->getJson("$api/tables")->assertNotFound();
    $this->actingAs($u['owner'])->putJson("$api/tables/$foreign", tableData())->assertNotFound();
    $this->actingAs($u['owner'])->deleteJson("$api/tables/$foreign")->assertNotFound();
    expect(Table::find($foreign))->not->toBeNull();
});
