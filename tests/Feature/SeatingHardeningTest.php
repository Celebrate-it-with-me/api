<?php

use App\Exceptions\SeatingException;
use App\Exports\Roster\VenueTablesSheet;
use App\Http\Services\AppServices\Seating\TableServices;
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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{0: Events, 1: array<string, User>, 2: string} */
function hxEvent(): array
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
    ]);
    $users = [];
    foreach (['owner', 'editor', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }

    return [$event, $users, "/api/v1/app/event/{$event->id}"];
}

function hxTable(Events $event, array $extra = []): Table
{
    return Table::create(array_merge(['event_id' => $event->id, 'name' => 'Mesa 1', 'capacity' => 4, 'type' => 'family', 'priority' => 1], $extra));
}

function hxGuest(Events $event, array $extra = []): Guest
{
    return Guest::factory()->create(array_merge(['event_id' => $event->id, 'parent_id' => null, 'rsvp_status' => 'attending'], $extra));
}

function hxSeat(Table $table, Guest $guest, string $seat = '1', int $by = 0): TableAssignment
{
    return TableAssignment::create([
        'table_id' => $table->id, 'guest_id' => $guest->id, 'seat_number' => $seat,
        'assigned_by' => $by ?: User::factory()->create()->id,
    ]);
}

/** A delete that fires no model events, like a bulk delete does. */
function hxSilentDelete(Guest ...$guests): void
{
    Guest::query()->whereIn('id', collect($guests)->pluck('id'))->update(['deleted_at' => now()]);
}

// ---------------------------------------------------------------- B1: deleted guests free their seat

it('B1: deleting a main guest frees her seat and those of her companions', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $main = hxGuest($event);
    $kid = hxGuest($event, ['parent_id' => $main->id]);
    $other = hxGuest($event);
    hxSeat($table, $main, '1');
    hxSeat($table, $kid, '2');
    hxSeat($table, $other, '3');

    $this->actingAs($u['owner'])->deleteJson("$api/roster/{$main->id}")->assertOk();

    expect(TableAssignment::withoutGlobalScopes()->pluck('guest_id')->all())->toBe([$other->id]);
    $this->actingAs($u['owner'])->getJson("$api/tables")->assertOk()
        ->assertJsonPath('meta.total_assigned', 1)
        ->assertJsonPath('data.0.assigned_count', 1)
        ->assertJsonPath('data.0.available_seats', 3);
});

it('B1: deleting a companion frees only her seat', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $main = hxGuest($event);
    $kid = hxGuest($event, ['parent_id' => $main->id]);
    hxSeat($table, $main, '1');
    hxSeat($table, $kid, '2');

    $this->actingAs($u['owner'])->deleteJson("$api/roster/{$kid->id}")->assertOk();

    expect(TableAssignment::withoutGlobalScopes()->pluck('guest_id')->all())->toBe([$main->id]);
});

it('B1: a silent bulk delete leaves a ghost row that never counts, never lists and never blocks a seat', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event, ['capacity' => 1]);
    $ghost = hxGuest($event);
    $alive = hxGuest($event);
    hxSeat($table, $ghost, '1');
    hxSilentDelete($ghost);

    expect(TableAssignment::withoutGlobalScopes()->count())->toBe(1)->and(TableAssignment::count())->toBe(0);
    $list = $this->actingAs($u['owner'])->getJson("$api/tables")->assertOk();
    expect($list->json('data.0.assigned_count'))->toBe(0)
        ->and($list->json('data.0.is_full'))->toBeFalse()
        ->and($list->json('data.0.assigned_guests'))->toBe([])
        ->and($list->json('meta.total_assigned'))->toBe(0);

    $this->actingAs($u['owner'])->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $alive->id])->assertOk();
});

it('B1: clear-all and the table delete count only live guests, and clear-all also removes ghosts', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $other = hxTable($event, ['name' => 'Mesa 2']);
    [$a, $b, $ghost] = [hxGuest($event), hxGuest($event), hxGuest($event)];
    hxSeat($table, $a, '1');
    hxSeat($table, $ghost, '2');
    hxSeat($other, $b, '1');
    hxSilentDelete($ghost);

    $this->actingAs($u['owner'])->deleteJson("$api/tables/{$table->id}")->assertOk()->assertJsonPath('meta.guests_unassigned', 1);
    $this->actingAs($u['owner'])->deleteJson("$api/seating/clear-all")->assertOk()->assertJsonPath('meta.guests_unassigned', 1);
    expect(TableAssignment::withoutGlobalScopes()->count())->toBe(0);
});

it('B1: the cleaning migration removes ghosts, converts seat labels and is safe to run twice', function () {
    [$event] = hxEvent();
    $table = hxTable($event);
    [$ghost, $alive, $noNumber, $already] = [hxGuest($event), hxGuest($event), hxGuest($event), hxGuest($event)];
    hxSeat($table, $ghost, 'Mesa 1 - Seat 4');
    hxSeat($table, $alive, 'Mesa 1 - Seat 12');
    hxSeat($table, $noNumber, 'Sin número');
    hxSeat($table, $already, '7');
    hxSilentDelete($ghost);
    $before = TableAssignment::withoutGlobalScopes()->where('guest_id', $already->id)->first()->updated_at;

    $migration = require base_path('database/migrations/2027_07_10_000001_clean_seating_ghosts_and_seat_numbers.php');
    $migration->up();
    $migration->up();
    $migration->down();

    $seats = TableAssignment::withoutGlobalScopes()->orderBy('id')->pluck('seat_number', 'guest_id')->all();
    expect($seats)->toBe([$alive->id => '12', $noNumber->id => '', $already->id => '7'])
        ->and(TableAssignment::withoutGlobalScopes()->where('guest_id', $already->id)->first()->updated_at->eq($before))->toBeTrue();
});

// ---------------------------------------------------------------- B2: validation of the guest id

it('B2: a bad guest_id is a 422, never a 500', function () {
    [$event, $u, $api] = hxEvent();
    [$otherEvent] = hxEvent();
    $table = hxTable($event);
    $gone = hxGuest($event);
    hxSilentDelete($gone);
    $foreign = hxGuest($otherEvent);
    $post = fn ($id) => $this->actingAs($u['owner'])->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $id]);

    foreach (['abc', '1.5', ['1'], null, '', 999999, $gone->id, $foreign->id] as $bad) {
        $post($bad)->assertStatus(422)->assertJsonValidationErrors('guest_id');
    }
    expect(TableAssignment::withoutGlobalScopes()->count())->toBe(0);
});

it('B2: the validation messages follow the language of the request', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $url = "$api/tables/{$table->id}/assign";

    $this->actingAs($u['owner'])->postJson($url, ['guest_id' => 'abc'], ['X-Locale' => 'en'])->assertJsonPath('errors.guest_id.0', 'The guest is not valid.');
    $this->actingAs($u['owner'])->postJson($url, ['guest_id' => 'abc'], ['X-Locale' => 'es'])->assertJsonPath('errors.guest_id.0', 'El invitado no es válido.');
    $this->actingAs($u['owner'])->postJson("$api/tables", ['name' => '', 'capacity' => 1, 'type' => 'vip', 'priority' => 1], ['X-Locale' => 'en'])->assertJsonPath('errors.name.0', 'The table name is required.');
    $this->actingAs($u['owner'])->putJson("$api/tables/{$table->id}", ['capacity' => 0], ['X-Locale' => 'es'])->assertJsonPath('errors.capacity.0', 'La mesa necesita al menos 1 lugar.');
});

// ---------------------------------------------------------------- B3: seat numbers and localized texts

it('B3: a seat is stored as a bare number, numbered after the highest one, and a rename does not touch it', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    [$a, $b, $c] = [hxGuest($event), hxGuest($event), hxGuest($event)];
    foreach ([$a, $b, $c] as $g) {
        $this->actingAs($u['owner'])->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $g->id])->assertOk();
    }
    expect(TableAssignment::orderBy('id')->pluck('seat_number')->all())->toBe(['1', '2', '3']);

    // Removing the first closes the gap; the next one never repeats a number.
    $this->actingAs($u['owner'])->deleteJson("$api/tables/{$table->id}/guest/{$a->id}")->assertOk();
    expect(TableAssignment::orderBy('id')->pluck('seat_number')->all())->toBe(['1', '2']);
    $d = hxGuest($event);
    $this->actingAs($u['owner'])->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $d->id])->assertOk();
    expect(TableAssignment::pluck('seat_number')->all())->toBe(['1', '2', '3']);

    $this->actingAs($u['owner'])->putJson("$api/tables/{$table->id}", ['name' => 'Mesa de honor'])->assertOk();
    expect(TableAssignment::pluck('seat_number')->all())->toBe(['1', '2', '3']);
    $this->actingAs($u['owner'])->getJson("$api/tables/{$table->id}")->assertOk()->assertJsonPath('data.assigned_guests.0.seatNumber', '1');
});

it('B3: legacy labels still give the right next seat', function () {
    [$event] = hxEvent();
    $table = hxTable($event);
    hxSeat($table, hxGuest($event), 'Mesa 1 - Seat 2');
    hxSeat($table, hxGuest($event), '');

    expect($table->getNextSeatNumber())->toBe('3');
});

it('B3: a full table answers 422 with the stable code table_full and a localized message', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event, ['capacity' => 1, 'name' => 'Honor']);
    hxSeat($table, hxGuest($event));
    $late = hxGuest($event);

    $r = $this->actingAs($u['owner'])->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $late->id], ['X-Locale' => 'en'])->assertStatus(422);
    expect($r->json('code'))->toBe('table_full')->and($r->json('message'))->toBe('Table Honor is full.')->and($r->json('errors.table'))->toBe(['full']);
    $this->actingAs($u['owner'])->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $late->id], ['X-Locale' => 'es'])
        ->assertStatus(422)->assertJsonPath('message', 'La mesa Honor está llena.');
});

it('B3: an already seated guest answers 422 guest_already_seated', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $other = hxTable($event, ['name' => 'Mesa 2']);
    $guest = hxGuest($event);
    hxSeat($table, $guest);

    $this->actingAs($u['owner'])->postJson("$api/tables/{$other->id}/assign", ['guest_id' => $guest->id])
        ->assertStatus(422)->assertJsonPath('code', 'guest_already_seated');
});

it('B3: the public invitation and the export show the bare seat', function () {
    [$event] = hxEvent();
    $table = hxTable($event);
    $guest = hxGuest($event);
    hxSeat($table, $guest, '5');

    $sheet = new VenueTablesSheet($event, true, 'en', false);
    expect($sheet->map($sheet->query()->first()))->toContain('5')->and($sheet->headings()[1])->toBe('Seat');
});

// ---------------------------------------------------------------- B8: export seat order

it('B8: the export lists seats in numeric order, then by table', function () {
    [$event] = hxEvent();
    $b = hxTable($event, ['name' => 'B', 'capacity' => 20]);
    $a = hxTable($event, ['name' => 'A', 'capacity' => 20]);
    foreach (['10', '2', '1', '9', '11'] as $seat) {
        hxSeat($b, hxGuest($event), $seat);
    }
    hxSeat($a, hxGuest($event), '3');
    $ghost = hxGuest($event);
    hxSeat($a, $ghost, '1');
    hxSilentDelete($ghost);

    $order = (new VenueTablesSheet($event, true, 'es', false))->query()->get()->map(fn ($x) => $x->table->name . $x->seat_number)->all();

    expect($order)->toBe(['A3', 'B1', 'B2', 'B9', 'B10', 'B11']);
});

// ---------------------------------------------------------------- B5/B6: authorization and locks

it('B5: a viewer reads but cannot change anything in seating', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $other = hxTable($event, ['name' => 'Mesa 2']);
    [$a, $b] = [hxGuest($event), hxGuest($event)];
    hxSeat($table, $a);
    $v = fn () => $this->actingAs($u['viewer']);

    $v()->getJson("$api/tables")->assertOk();
    $v()->getJson("$api/tables/{$table->id}")->assertOk();
    $v()->getJson("$api/seating/unassigned")->assertOk();
    $v()->postJson("$api/tables", ['name' => 'X', 'capacity' => 2, 'type' => 'vip', 'priority' => 1])->assertForbidden();
    $v()->putJson("$api/tables/{$table->id}", ['name' => 'Y'])->assertForbidden();
    $v()->deleteJson("$api/tables/{$table->id}")->assertForbidden();
    $v()->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $b->id])->assertForbidden();
    $v()->deleteJson("$api/tables/{$table->id}/guest/{$a->id}")->assertForbidden();
    $v()->postJson("$api/seating/move", ['guest_id' => $a->id, 'to_table_id' => $other->id])->assertForbidden();
    $v()->postJson("$api/seating/bulk-assign", ['assignments' => [['table_id' => $table->id, 'guest_ids' => [$b->id]]]])->assertForbidden();
    $v()->deleteJson("$api/seating/clear-all")->assertForbidden();

    expect(TableAssignment::count())->toBe(1)->and(Table::count())->toBe(2);
});

it('B5: an editor can do everything in seating, and a stranger sees nothing', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $guest = hxGuest($event);
    $e = fn () => $this->actingAs($u['editor']);

    $e()->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $guest->id])->assertOk();
    $e()->deleteJson("$api/seating/clear-all")->assertOk();
    $e()->deleteJson("$api/tables/{$table->id}")->assertOk();
    expect(Table::count())->toBe(0);

    $this->actingAs(User::factory()->create())->getJson("$api/seating/unassigned")->assertNotFound();
});

it('B6: assigning, moving and bulk assigning lock the table row', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $other = hxTable($event, ['name' => 'Mesa 2']);
    [$a, $b] = [hxGuest($event), hxGuest($event)];
    $locks = function (callable $call): int {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $call();

        return collect($queries)->filter(fn ($sql) => str_contains(strtolower($sql), 'for update') && str_contains($sql, '"tables"'))->count();
    };

    expect($locks(fn () => $this->actingAs($u['owner'])->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $a->id])->assertOk()))->toBeGreaterThan(0);
    expect($locks(fn () => $this->actingAs($u['owner'])->postJson("$api/seating/move", ['guest_id' => $a->id, 'to_table_id' => $other->id])->assertOk()))->toBeGreaterThan(0);
    expect($locks(fn () => $this->actingAs($u['owner'])->postJson("$api/seating/bulk-assign", ['assignments' => [['table_id' => $table->id, 'guest_ids' => [$b->id]]]])->assertOk()))->toBeGreaterThan(0);
});

it('B6: capacity is re-checked inside the lock, so the last seat cannot be taken twice', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event, ['capacity' => 1]);
    [$a, $b] = [hxGuest($event), hxGuest($event)];

    // The second request sees the table the first one already filled, even if the route loaded it before.
    $service = app(TableServices::class);
    $stale = Table::find($table->id);
    $service->assignGuest($table, $a->id, $u['owner']->id);

    expect(fn () => $service->assignGuest($stale, $b->id, $u['owner']->id))->toThrow(SeatingException::class);
    expect(TableAssignment::count())->toBe(1);
});

it('B6: bulk-assign never overbooks, repeats a seat number or seats a guest twice', function () {
    [$event, $u, $api] = hxEvent();
    $small = hxTable($event, ['capacity' => 2, 'name' => 'Small']);
    $big = hxTable($event, ['capacity' => 10, 'name' => 'Big']);
    $guests = collect(range(1, 4))->map(fn () => hxGuest($event));
    $ids = $guests->pluck('id')->all();

    $r = $this->actingAs($u['owner'])->postJson("$api/seating/bulk-assign", ['assignments' => [
        ['table_id' => $small->id, 'guest_ids' => [$ids[0], $ids[1], $ids[2]]],
        ['table_id' => $big->id, 'guest_ids' => [$ids[0], $ids[2], $ids[2], $ids[3]]],
    ]], ['X-Locale' => 'en'])->assertOk();

    expect($r->json('meta.total_assigned'))->toBe(4)->and($r->json('meta.total_skipped'))->toBe(3);
    expect(collect($r->json('meta.errors'))->pluck('code')->all())->toBe(['table_full', 'guest_already_seated', 'guest_already_seated']);
    expect($r->json('meta.errors.0'))->toBe(['code' => 'table_full', 'table_id' => $small->id, 'guest_id' => $ids[2], 'message' => 'Table Small is full.']);
    expect(TableAssignment::where('table_id', $small->id)->orderBy('id')->pluck('seat_number')->all())->toBe(['1', '2'])
        ->and(TableAssignment::where('table_id', $big->id)->orderBy('id')->pluck('seat_number')->all())->toBe(['1', '2'])
        ->and(TableAssignment::distinct('guest_id')->count('guest_id'))->toBe(4);
});

it('B6: bulk-assign answers in Spanish by default and validates the body', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event, ['capacity' => 1, 'name' => 'Honor']);
    [$a, $b] = [hxGuest($event), hxGuest($event)];
    $bulk = fn (array $body, array $h = []) => $this->actingAs($u['owner'])->postJson("$api/seating/bulk-assign", $body, $h);

    $bulk(['assignments' => [['table_id' => $table->id, 'guest_ids' => [$a->id, $b->id]]]], ['X-Locale' => 'es'])->assertOk()
        ->assertJsonPath('message', 'Asignación en bloque terminada.')->assertJsonPath('meta.errors.0.message', 'La mesa Honor está llena.');
    $bulk(['assignments' => []])->assertStatus(422);
    $bulk(['assignments' => [['table_id' => 'x', 'guest_ids' => [$a->id]]]])->assertStatus(422)->assertJsonValidationErrors('assignments.0.table_id');
    $bulk(['assignments' => [['table_id' => $table->id, 'guest_ids' => ['x']]]])->assertStatus(422)->assertJsonValidationErrors('assignments.0.guest_ids.0');
});

// ---------------------------------------------------------------- move

it('moves a guest between tables in one step and returns both tables', function () {
    [$event, $u, $api] = hxEvent();
    $from = hxTable($event, ['capacity' => 3]);
    $to = hxTable($event, ['name' => 'Mesa 2', 'capacity' => 3]);
    [$a, $b, $c] = [hxGuest($event), hxGuest($event), hxGuest($event)];
    hxSeat($from, $a, '1');
    hxSeat($from, $b, '2');
    hxSeat($to, $c, '1');

    $r = $this->actingAs($u['owner'])->postJson("$api/seating/move", ['guest_id' => $a->id, 'to_table_id' => $to->id])->assertOk();

    expect($r->json('success'))->toBeTrue()
        ->and($r->json('data.from.id'))->toBe($from->id)->and($r->json('data.from.assigned_count'))->toBe(1)
        ->and($r->json('data.from.assigned_guests.0.id'))->toBe($b->id)->and($r->json('data.from.assigned_guests.0.seatNumber'))->toBe('1')
        ->and($r->json('data.to.id'))->toBe($to->id)->and($r->json('data.to.assigned_count'))->toBe(2)
        ->and($r->json('data.to.available_seats'))->toBe(1)->and($r->json('data.to.is_full'))->toBeFalse()
        ->and($r->json('meta.declined'))->toBeFalse();
    expect(TableAssignment::where('guest_id', $a->id)->first())->table_id->toBe($to->id)->seat_number->toBe('2');
});

it('does not move into a full table and leaves the guest where she was', function () {
    [$event, $u, $api] = hxEvent();
    $from = hxTable($event);
    $full = hxTable($event, ['name' => 'Llena', 'capacity' => 1]);
    [$a, $b] = [hxGuest($event), hxGuest($event)];
    hxSeat($from, $a, '1');
    hxSeat($full, $b, '1');

    $this->actingAs($u['owner'])->postJson("$api/seating/move", ['guest_id' => $a->id, 'to_table_id' => $full->id])
        ->assertStatus(422)->assertJsonPath('code', 'table_full');

    expect(TableAssignment::where('guest_id', $a->id)->first()->table_id)->toBe($from->id);
});

it('refuses to move a guest to her own table, a guest without a table or a table of another event', function () {
    [$event, $u, $api] = hxEvent();
    [$otherEvent] = hxEvent();
    $table = hxTable($event);
    $foreignTable = hxTable($otherEvent);
    $seated = hxGuest($event);
    $free = hxGuest($event);
    hxSeat($table, $seated);
    $move = fn (array $b) => $this->actingAs($u['owner'])->postJson("$api/seating/move", $b);

    $move(['guest_id' => $seated->id, 'to_table_id' => $table->id])->assertStatus(422)->assertJsonPath('code', 'same_table');
    $move(['guest_id' => $free->id, 'to_table_id' => $table->id])->assertStatus(422)->assertJsonPath('code', 'guest_not_seated');
    $move(['guest_id' => $seated->id, 'to_table_id' => $foreignTable->id])->assertStatus(422)->assertJsonValidationErrors('to_table_id');
    $move(['guest_id' => 'abc', 'to_table_id' => 'zz'])->assertStatus(422)->assertJsonValidationErrors(['guest_id', 'to_table_id']);
    $move([])->assertStatus(422)->assertJsonValidationErrors(['guest_id', 'to_table_id']);
    expect(TableAssignment::where('guest_id', $seated->id)->first()->table_id)->toBe($table->id);
});

it('warns when a declined guest is seated or moved', function () {
    [$event, $u, $api] = hxEvent();
    $a = hxTable($event);
    $b = hxTable($event, ['name' => 'Mesa 2']);
    $declined = hxGuest($event, ['rsvp_status' => 'not-attending']);
    $pending = hxGuest($event, ['rsvp_status' => 'pending']);

    $this->actingAs($u['owner'])->postJson("$api/tables/{$a->id}/assign", ['guest_id' => $declined->id])->assertOk()->assertJsonPath('meta.declined', true);
    $this->actingAs($u['owner'])->postJson("$api/tables/{$a->id}/assign", ['guest_id' => $pending->id])->assertOk()->assertJsonPath('meta.declined', false);
    $this->actingAs($u['owner'])->postJson("$api/seating/move", ['guest_id' => $declined->id, 'to_table_id' => $b->id])->assertOk()->assertJsonPath('meta.declined', true);

    $c = hxGuest($event, ['rsvp_status' => 'not-attending']);
    $this->actingAs($u['owner'])->postJson("$api/seating/bulk-assign", ['assignments' => [['table_id' => $b->id, 'guest_ids' => [$c->id]]]])
        ->assertOk()->assertJsonPath('meta.declined_ids', [$c->id]);
});

it('changes the public invitation ETag and the table it shows when a guest moves', function () {
    [$event, $u, $api] = hxEvent();
    $event->forceFill(['status' => 'published', 'custom_url_slug' => 'xv-mover'])->save();
    $a = hxTable($event, ['name' => 'Mesa A']);
    $b = hxTable($event, ['name' => 'Mesa B']);
    $guest = hxGuest($event, ['code' => 'MOV123']);
    hxSeat($a, $guest, '1');
    $url = '/api/v1/app/invitation/xv-mover/MOV123';
    $first = $this->getJson($url)->assertOk();
    $etag = $first->headers->get('ETag');
    $this->getJson($url, ['If-None-Match' => $etag])->assertStatus(304);

    $this->actingAs($u['owner'])->postJson("$api/seating/move", ['guest_id' => $guest->id, 'to_table_id' => $b->id])->assertOk();
    $after = $this->getJson($url, ['If-None-Match' => $etag])->assertOk();

    $section = collect($after->json('data.sections'))->firstWhere('type', 'tables');
    expect($after->headers->get('ETag'))->not->toBe($etag)
        ->and($section['data']['tables'][0])->toMatchArray(['table' => 'Mesa B', 'seat' => '1']);
});

// ---------------------------------------------------------------- unassigned

it('lists the unassigned guests with search, status, pagination, companionOf and declined', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $rosa = hxGuest($event, ['name' => 'Rosa Fernández', 'rsvp_status' => 'attending']);
    $kid = hxGuest($event, ['name' => 'Pedrito', 'parent_id' => $rosa->id, 'rsvp_status' => 'pending']);
    $no = hxGuest($event, ['name' => 'Tío Lalo', 'rsvp_status' => 'not-attending', 'email' => 'lalo@example.com']);
    $seated = hxGuest($event, ['name' => 'Sentada']);
    $gone = hxGuest($event, ['name' => 'Borrada']);
    hxSeat($table, $seated);
    hxSilentDelete($gone);
    [$foreign] = hxEvent();
    hxGuest($foreign, ['name' => 'Ajena']);
    $get = fn (string $q = '') => $this->actingAs($u['viewer'])->getJson("$api/seating/unassigned$q")->assertOk();

    $all = $get();
    expect($all->json('data.*.name'))->toBe(['Pedrito', 'Rosa Fernández', 'Tío Lalo'])
        ->and($all->json('meta'))->toMatchArray(['total_unassigned' => 3, 'attending_unassigned' => 1, 'pending_unassigned' => 1, 'declined_unassigned' => 1, 'total' => 3, 'page' => 1, 'last_page' => 1]);
    $kidRow = collect($all->json('data'))->firstWhere('id', $kid->id);
    $noRow = collect($all->json('data'))->firstWhere('id', $no->id);
    expect($kidRow['companionOf'])->toBe(['id' => $rosa->id, 'name' => 'Rosa Fernández'])->and($kidRow['rsvpStatus'])->toBe('pending')->and($kidRow['declined'])->toBeFalse()
        ->and($noRow['declined'])->toBeTrue()->and($noRow['companionOf'])->toBeNull();

    expect($get('?status=not-attending')->json('data.*.name'))->toBe(['Tío Lalo'])
        ->and($get('?status=attending')->json('data.*.name'))->toBe(['Rosa Fernández'])
        ->and($get('?status=pending')->json('data.*.name'))->toBe(['Pedrito'])
        ->and($get('?status=all')->json('data'))->toHaveCount(3)
        ->and($get('?search=lalo@')->json('data.*.name'))->toBe(['Tío Lalo'])
        ->and($get('?search=ROSA')->json('data.*.name'))->toBe(['Pedrito', 'Rosa Fernández'])
        ->and($get('?search=%25')->json('data'))->toBe([]);

    $page = $get('?per_page=2&page=2');
    expect($page->json('data.*.name'))->toBe(['Tío Lalo'])->and($page->json('meta'))->toMatchArray(['total' => 3, 'total_unassigned' => 3, 'page' => 2, 'per_page' => 2, 'last_page' => 2]);
    $filtered = $get('?status=attending&per_page=1');
    expect($filtered->json('meta.total'))->toBe(1)->and($filtered->json('meta.total_unassigned'))->toBe(3);
});

it('validates the unassigned filters', function () {
    [, $u, $api] = hxEvent();

    foreach (['?status=maybe', '?per_page=0', '?per_page=500', '?page=0', '?per_page=abc'] as $q) {
        $this->actingAs($u['owner'])->getJson("$api/seating/unassigned$q")->assertStatus(422);
    }
});

// ---------------------------------------------------------------- coherent occupancy, removals, rename

it('reports the same occupancy in the list, the detail and the guests it shows', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event, ['capacity' => 3]);
    [$a, $b, $ghost] = [hxGuest($event), hxGuest($event), hxGuest($event)];
    hxSeat($table, $a, '1');
    hxSeat($table, $b, '2');
    hxSeat($table, $ghost, '3');
    hxSilentDelete($ghost);

    foreach (["$api/tables", "$api/tables/{$table->id}"] as $url) {
        $json = $this->actingAs($u['owner'])->getJson($url)->assertOk()->json('data');
        $t = array_is_list($json) ? $json[0] : $json;
        expect($t['assigned_count'])->toBe(2)->and(count($t['assigned_guests']))->toBe(2)
            ->and($t['available_seats'])->toBe(1)->and($t['is_full'])->toBeFalse()->and($t['occupancy_percentage'])->toBe(66.67);
    }
});

it('removes a guest from a table, 404s with a code if she is not there, and renumbers', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $other = hxTable($event, ['name' => 'Mesa 2']);
    [$a, $b, $c] = [hxGuest($event), hxGuest($event), hxGuest($event)];
    hxSeat($table, $a, '1');
    hxSeat($table, $b, '2');
    hxSeat($other, $c, '1');

    $this->actingAs($u['owner'])->deleteJson("$api/tables/{$table->id}/guest/{$c->id}")->assertNotFound()->assertJsonPath('code', 'guest_not_at_table');
    $this->actingAs($u['owner'])->deleteJson("$api/tables/{$table->id}/guest/{$a->id}")->assertOk()->assertJsonPath('data.assigned_count', 1);
    expect(TableAssignment::where('guest_id', $b->id)->value('seat_number'))->toBe('1');
});

it('a table of another event is a 404 on every table route', function () {
    [$event, $u, $api] = hxEvent();
    [$otherEvent] = hxEvent();
    $foreign = hxTable($otherEvent);
    $guest = hxGuest($event);

    $this->actingAs($u['owner'])->getJson("$api/tables/{$foreign->id}")->assertNotFound();
    $this->actingAs($u['owner'])->postJson("$api/tables/{$foreign->id}/assign", ['guest_id' => $guest->id])->assertNotFound();
    $this->actingAs($u['owner'])->deleteJson("$api/tables/{$foreign->id}/guest/{$guest->id}")->assertNotFound();
});

it('the service renumbers seats in order and skips a table already in order', function () {
    [$event] = hxEvent();
    $table = hxTable($event);
    [$a, $b, $c] = [hxGuest($event), hxGuest($event), hxGuest($event)];
    hxSeat($table, $a, '5');
    hxSeat($table, $b, 'Mesa 1 - Seat 2');
    hxSeat($table, $c, '');

    app(TableServices::class)->renumber($table);

    expect(TableAssignment::orderBy('seat_number')->pluck('guest_id')->all())->toBe([$b->id, $a->id, $c->id])
        ->and(TableAssignment::where('guest_id', $b->id)->value('seat_number'))->toBe('1')
        ->and(TableAssignment::where('guest_id', $a->id)->value('seat_number'))->toBe('2')
        ->and(TableAssignment::where('guest_id', $c->id)->value('seat_number'))->toBe('3');
});

it('the table list API surfaces a failure as a localized 500', function () {
    [$event, $u, $api] = hxEvent();
    $this->mock(TableServices::class, fn ($m) => $m->shouldReceive('getTablesForEvent')->andThrow(new RuntimeException('boom')));

    $this->actingAs($u['owner'])->getJson("$api/tables", ['X-Locale' => 'en'])->assertStatus(500)->assertJsonPath('message', 'The tables could not be loaded.');
});

it('every seating action surfaces an unexpected failure as a localized 500', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event);
    $other = hxTable($event, ['name' => 'Mesa 2']);
    $guest = hxGuest($event);
    $boom = new RuntimeException('boom');
    $this->mock(TableServices::class, function ($m) use ($boom) {
        foreach (['assignGuest', 'moveGuest', 'removeGuest', 'bulkAssign', 'clearAllAssignments'] as $method) {
            $m->shouldReceive($method)->andThrow($boom);
        }
    });
    $h = ['X-Locale' => 'en'];

    $this->actingAs($u['owner'])->postJson("$api/tables/{$table->id}/assign", ['guest_id' => $guest->id], $h)->assertStatus(500)->assertJsonPath('message', 'The guest could not be seated.');
    $this->actingAs($u['owner'])->postJson("$api/seating/move", ['guest_id' => $guest->id, 'to_table_id' => $other->id], $h)->assertStatus(500)->assertJsonPath('message', 'The guest could not be moved.');
    $this->actingAs($u['owner'])->deleteJson("$api/tables/{$table->id}/guest/{$guest->id}", [], $h)->assertStatus(500)->assertJsonPath('message', 'The guest could not be removed.');
    $this->actingAs($u['owner'])->postJson("$api/seating/bulk-assign", ['assignments' => [['table_id' => $table->id, 'guest_ids' => [$guest->id]]]], $h)->assertStatus(500)->assertJsonPath('message', 'The bulk assignment could not be completed.');
    $this->actingAs($u['owner'])->deleteJson("$api/seating/clear-all", [], $h)->assertStatus(500)->assertJsonPath('message', 'The tables could not be emptied.');
});

it('the service rejects a guest or a table that is not of the event', function () {
    [$event, $u] = hxEvent();
    [$otherEvent] = hxEvent();
    $table = hxTable($event);
    $foreignTable = hxTable($otherEvent);
    $foreignGuest = hxGuest($otherEvent);
    $guest = hxGuest($event);
    hxSeat($table, $guest);
    $service = app(TableServices::class);
    $code = function (callable $call) {
        try {
            $call();
        } catch (SeatingException $e) {
            return $e->errorCode;
        }
    };

    expect($code(fn () => $service->assignGuest($table, $foreignGuest->id, $u['owner']->id)))->toBe('guest_not_in_event')
        ->and($code(fn () => $service->assignGuest($table, 999999, $u['owner']->id)))->toBe('guest_not_found')
        ->and($code(fn () => $service->moveGuest($event, $guest->id, $foreignTable->id, $u['owner']->id)))->toBe('table_not_in_event')
        ->and($code(fn () => $service->moveGuest($event, 999999, $table->id, $u['owner']->id)))->toBe('guest_not_found');

    $result = $service->bulkAssign($event, [['table_id' => $foreignTable->id, 'guest_ids' => [$guest->id]], ['table_id' => $table->id, 'guest_ids' => [$foreignGuest->id]]], $u['owner']->id);
    expect(collect($result['errors'])->pluck('code')->all())->toBe(['table_not_in_event', 'guest_not_in_event'])->and($result['total_assigned'])->toBe(0);
});

it('does not let the capacity drop below the seated people, and says so in the language asked', function () {
    [$event, $u, $api] = hxEvent();
    $table = hxTable($event, ['capacity' => 4]);
    hxSeat($table, hxGuest($event), '1');
    hxSeat($table, hxGuest($event), '2');

    $this->actingAs($u['owner'])->putJson("$api/tables/{$table->id}", ['capacity' => 1], ['X-Locale' => 'en'])
        ->assertStatus(422)->assertJsonPath('errors.capacity.0', 'You cannot lower the capacity below the people already seated (2).');
    $this->actingAs($u['owner'])->putJson("$api/tables/{$table->id}", ['capacity' => 2])->assertOk()->assertJsonPath('data.is_full', true);
});
