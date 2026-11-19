<?php

use App\Exports\Roster\VenueExport;
use App\Exports\Roster\VenueTablesSheet;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Seating\Table;
use App\Models\Seating\TableAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function scvEvent(): Events
{
    return Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
    ]);
}

function scvTable(Events $event, string $name = 'Mesa 1', int $capacity = 4, array $extra = []): Table
{
    return Table::create(array_merge(['event_id' => $event->id, 'name' => $name, 'capacity' => $capacity, 'type' => 'family', 'priority' => 1], $extra));
}

function scvSeat(Table $table, Guest $guest, string $seat): TableAssignment
{
    return TableAssignment::create(['table_id' => $table->id, 'guest_id' => $guest->id, 'seat_number' => $seat, 'assigned_by' => User::factory()->create()->id]);
}

function scvGuest(Events $event, array $extra = []): Guest
{
    return Guest::factory()->create(array_merge(['event_id' => $event->id, 'parent_id' => null, 'rsvp_status' => 'attending'], $extra));
}

function scvLoad(object $export): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    file_put_contents($path, Excel::raw($export, ExcelWriter::XLSX));
    $book = IOFactory::load($path);
    @unlink($path);

    return $book;
}

// ---------------------------------------------------------------- Table

it('counts seats from a fresh query, or from the preloaded aggregate when the list carries it', function () {
    $event = scvEvent();
    $table = scvTable($event, 'Mesa 1', 4);
    scvSeat($table, scvGuest($event), '1');
    scvSeat($table, scvGuest($event), '2');

    $fresh = Table::find($table->id);
    expect($fresh->assignedCount())->toBe(2)
        ->and($fresh->available_seats)->toBe(2)
        ->and($fresh->occupancy_percentage)->toBe(50.0)
        ->and($fresh->hasAvailableSeats())->toBeTrue()
        ->and($fresh->canAssignGuest())->toBeTrue()
        ->and($fresh->isFull())->toBeFalse();

    // withAssignedCount() makes the number travel with the row: it is read as is, with no second query.
    $counted = Table::withAssignedCount()->find($table->id);
    expect($counted->getAttributes())->toHaveKey('assigned_guests_count');
    DB::enableQueryLog();
    expect($counted->assignedCount())->toBe(2)->and($counted->isFull())->toBeFalse();
    expect(DB::getQueryLog())->toBe([]);
});

it('knows when a table is full and when it has no capacity at all', function () {
    $event = scvEvent();
    $full = scvTable($event, 'Llena', 1);
    scvSeat($full, scvGuest($event), '1');
    $zero = scvTable($event, 'Sin sillas', 0);

    expect($full->isFull())->toBeTrue()
        ->and($full->hasAvailableSeats())->toBeFalse()
        ->and($full->canAssignGuest())->toBeFalse()
        ->and($full->available_seats)->toBe(0)
        ->and($full->occupancy_percentage)->toBe(100.0)
        ->and($zero->occupancy_percentage)->toBe(0.0)
        ->and($zero->isFull())->toBeTrue();
});

it('hands out the next seat after the highest number, ignoring text and gaps', function () {
    $event = scvEvent();
    $table = scvTable($event, 'Mesa 1', 20);
    expect($table->getNextSeatNumber())->toBe('1');

    scvSeat($table, scvGuest($event), '3');
    scvSeat($table, scvGuest($event), 'Seat 7');
    scvSeat($table, scvGuest($event), 'VIP');
    expect($table->getNextSeatNumber())->toBe('8');
});

it('filters and orders tables with its scopes and reaches the event, the assignments and the seated guests', function () {
    $event = scvEvent();
    $other = scvEvent();
    $a = scvTable($event, 'A', 4, ['type' => 'family', 'priority' => 1]);
    $b = scvTable($event, 'B', 4, ['type' => 'vip', 'priority' => 5]);
    scvTable($other, 'Z', 4, ['type' => 'vip', 'priority' => 9]);

    expect(Table::forEvent($event->id)->count())->toBe(2)
        ->and(Table::forEvent($event->id)->ofType('vip')->pluck('name')->all())->toBe(['B'])
        ->and(Table::forEvent($event->id)->byPriority()->pluck('name')->all())->toBe(['B', 'A'])
        ->and(Table::forEvent($event->id)->byPriority('asc')->pluck('name')->all())->toBe(['A', 'B'])
        ->and($a->event->is($event))->toBeTrue();

    $guest = scvGuest($event);
    scvSeat($a, $guest, '1');
    expect($a->assignments)->toHaveCount(1)
        ->and($a->assignedGuests()->get()->pluck('id')->all())->toBe([$guest->id]);
});

// ---------------------------------------------------------------- TableAssignment

it('stamps assigned_at on creation unless one is given, and keeps who assigned', function () {
    $event = scvEvent();
    $table = scvTable($event);
    $by = User::factory()->create();

    $auto = TableAssignment::create(['table_id' => $table->id, 'guest_id' => scvGuest($event)->id, 'seat_number' => '1', 'assigned_by' => $by->id]);
    expect($auto->assigned_at)->not->toBeNull()
        ->and($auto->assignedBy->is($by))->toBeTrue()
        ->and($auto->table->is($table))->toBeTrue();

    $given = TableAssignment::create(['table_id' => $table->id, 'guest_id' => scvGuest($event)->id, 'seat_number' => '2', 'assigned_at' => '2027-01-02 10:00:00', 'assigned_by' => $by->id]);
    expect($given->fresh()->assigned_at->toDateTimeString())->toBe('2027-01-02 10:00:00');
});

it('filters assignments by table or guest and lists the recent ones first', function () {
    $event = scvEvent();
    $t1 = scvTable($event, 'M1');
    $t2 = scvTable($event, 'M2');
    [$g1, $g2, $g3] = [scvGuest($event), scvGuest($event), scvGuest($event)];
    scvSeat($t1, $g1, '1')->update(['assigned_at' => '2027-01-01 10:00:00']);
    scvSeat($t1, $g2, '2')->update(['assigned_at' => '2027-01-03 10:00:00']);
    scvSeat($t2, $g3, '1')->update(['assigned_at' => '2027-01-02 10:00:00']);

    expect(TableAssignment::forTable($t1->id)->count())->toBe(2)
        ->and(TableAssignment::forGuest($g3->id)->first()->table_id)->toBe($t2->id)
        ->and(TableAssignment::recent()->pluck('guest_id')->all())->toBe([$g2->id, $g3->id, $g1->id]);
});

it('hides the assignments of a guest deleted without model events, in every read', function () {
    $event = scvEvent();
    $table = scvTable($event);
    $alive = scvGuest($event);
    $ghost = scvGuest($event);
    scvSeat($table, $alive, '1');
    scvSeat($table, $ghost, '2');

    Guest::query()->whereKey($ghost->id)->update(['deleted_at' => now()]);

    expect(TableAssignment::count())->toBe(1)
        ->and($table->assignments()->count())->toBe(1)
        ->and(Table::withAssignedCount()->find($table->id)->assignedCount())->toBe(1)
        ->and(TableAssignment::withoutGlobalScopes()->count())->toBe(2)
        ->and(TableAssignment::forGuest($ghost->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------- VenueTablesSheet

it('writes the tables sheet of the venue export with menus, restrictions, families and the total', function () {
    $event = scvEvent();
    $menu = Menu::create(['event_id' => $event->id, 'title' => 'Boda']);
    $main = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Pollo asado', 'type' => 'main', 'diet_type' => null]);
    $starter = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Ensalada', 'type' => 'starter', 'diet_type' => null]);

    $table = scvTable($event, 'Mesa 1', 8);
    $ana = scvGuest($event, ['name' => 'Ana Núñez', 'allergies' => 'Nueces', 'notes' => 'Silla alta']);
    $luis = scvGuest($event, ['name' => 'Luis Núñez', 'parent_id' => $ana->id]);
    $pending = scvGuest($event, ['name' => 'Pendiente', 'rsvp_status' => 'pending']);
    $ana->selectedMenuItems()->attach([$main->id, $starter->id]);
    scvSeat($table, $ana, '2');
    scvSeat($table, $luis, '10');
    scvSeat($table, $pending, '1');

    $sheet = new VenueTablesSheet($event, false, 'es', true);
    expect($sheet->chunkSize())->toBe(500)
        ->and($sheet->title())->toBe(__('exports.venue.sheet_tables', [], 'es'))
        ->and($sheet->headings())->toHaveCount(7);

    $rows = scvLoad(new VenueExport($event, false, 'es'))->getSheet(1)->toArray();
    // Header, Ana (seat 2) and Luis (seat 10) in seat order; the pending one is left out; then the total line.
    expect($rows[0][0])->toBe(__('exports.venue.table', [], 'es'))
        ->and($rows[1][0])->toBe('Mesa 1')->and($rows[1][1])->toBe('2')->and($rows[1][2])->toBe('Ana Núñez')->and($rows[1][3])->toBe('Ana Núñez')
        ->and($rows[1][4])->toBe('Ensalada / Pollo asado')->and($rows[1][5])->toBe('Nueces')->and($rows[1][6])->toBe('Silla alta')
        ->and($rows[2][2])->toBe('Luis Núñez')->and($rows[2][3])->toBe('Ana Núñez')
        ->and(collect($rows)->flatten()->filter()->all())->not->toContain('Pendiente')
        ->and(collect($rows)->last()[1])->toBe('2');

    $everyone = scvLoad(new VenueExport($event, true, 'es'))->getSheet(1)->toArray();
    expect(collect($everyone)->flatten()->all())->toContain('Pendiente');
});

it('writes a lean tables sheet when the menu module is off and keeps hostile text inert', function () {
    $event = scvEvent();
    $table = scvTable($event, 'Mesa 1');
    $guest = scvGuest($event, ['name' => '=HYPERLINK("x")', 'notes' => '+cmd']);
    scvSeat($table, $guest, '1');

    $sheet = new VenueTablesSheet($event, true, 'en', false);
    expect($sheet->headings())->toHaveCount(5);

    $book = scvLoad(new VenueExport($event, true, 'en'));
    $cell = $book->getSheet(1)->getCell('C2');
    expect($cell->getDataType())->toBe('s')->and((string) $cell->getValue())->toContain('HYPERLINK');
});

it('orders the seats as zero-padded text on databases without regexp_replace', function () {
    $sheet = new VenueTablesSheet(scvEvent(), true, 'es', false);
    $order = new ReflectionMethod($sheet, 'seatOrder');

    expect($order->invoke($sheet))->toContain('regexp_replace')->toContain('NULLS LAST');

    DB::shouldReceive('connection->getDriverName')->andReturn('mysql');
    expect($order->invoke($sheet))->toBe("LPAD(table_assignments.seat_number, 6, '0')");
});
