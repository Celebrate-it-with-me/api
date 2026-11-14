<?php

use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Seating\Table;
use App\Models\Seating\TableAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** [event, users, base url]. `premium` plans include Menu and Tables; `basic` locks them. */
function exportEvent(string $plan = 'basic', string $locale = 'es'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => 'draft',
        'locale' => $locale,
        'event_name' => 'Los XV de Valentina',
        'custom_url_slug' => 'xv-export-' . uniqid(),
    ]);
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'editor', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/roster"];
}

function person(Events $event, string $name, string $status = 'attending', ?int $parent = null, array $extra = []): Guest
{
    return Guest::factory()->create(array_merge([
        'event_id' => $event->id, 'parent_id' => $parent, 'name' => $name, 'rsvp_status' => $status,
        'email' => null, 'phone' => null, 'notes' => null, 'locale' => null,
    ], $extra));
}

/** The spreadsheet a download response carries. */
function bookOf($response)
{
    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->baseResponse->getFile()->getContent());
    $book = IOFactory::load($path);
    @unlink($path);

    return $book;
}

/** One sheet of a download response. */
function sheetOf($response, int $index = 0)
{
    return bookOf($response)->getSheet($index);
}

/** Rows of a sheet as arrays of strings (no formulas evaluated). */
function rowsOf($sheet): array
{
    return $sheet->toArray(null, false, false, false);
}

describe('permissions', function () {
    it('answers 401 without a session and 404 to someone outside the event', function () {
        [, $u, $url] = exportEvent();

        $this->getJson("$url/export")->assertUnauthorized();
        $this->getJson("$url/export/venue")->assertUnauthorized();
        $this->actingAs($u['stranger'])->getJson("$url/export")->assertNotFound();
        $this->actingAs($u['stranger'])->getJson("$url/export/venue")->assertNotFound();
    });

    it('lets every member of the event read it, and refuses another event id', function () {
        [, $u, $url] = exportEvent();
        [, , $otherUrl] = exportEvent();

        foreach (['owner', 'editor', 'viewer'] as $role) {
            $this->actingAs($u[$role])->get("$url/export")->assertOk();
        }
        $this->actingAs($u['owner'])->getJson($otherUrl . '/export')->assertNotFound();
    });

    it('is limited to 10 downloads a minute', function () {
        [, $u, $url] = exportEvent();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($u['owner'])->get("$url/export")->assertOk();
        }
        $this->actingAs($u['owner'])->getJson("$url/export")->assertStatus(429);
    });

    it('validates the filters', function () {
        [, $u, $url] = exportEvent();

        $this->actingAs($u['owner'])->getJson("$url/export?status=nonsense")->assertUnprocessable();
        $this->actingAs($u['owner'])->getJson("$url/export?locale=fr")->assertUnprocessable();
        $this->actingAs($u['owner'])->getJson("$url/export/venue?scope=nonsense")->assertUnprocessable();
    });
});

describe('the organizer list', function () {
    it('is an xlsx named after the event and the date, read from the guests table', function () {
        [$event, $u, $url] = exportEvent();
        person($event, 'Ana');

        $response = $this->actingAs($u['owner'])->get("$url/export")->assertOk();

        expect($response->headers->get('content-disposition'))->toContain('invitados-' . $event->custom_url_slug . '-' . now()->format('Y-m-d') . '.xlsx')
            ->and($response->headers->get('content-type'))->toContain('spreadsheetml');
    });

    it('has one row per family with companions, people, phone in E.164, status and language', function () {
        [$event, $u, $url] = exportEvent();
        $ana = person($event, 'Ana Pérez', 'attending', null, ['phone' => '(305) 555-0101', 'email' => 'ana@example.com', 'notes' => 'Vegetariana', 'locale' => 'en']);
        person($event, 'Luis Pérez', 'attending', $ana->id);
        person($event, 'Marta Pérez', 'pending', $ana->id);
        person($event, 'Beto', 'pending', null, ['phone' => 'no es un teléfono']);

        $rows = rowsOf(sheetOf($this->actingAs($u['owner'])->get("$url/export")));

        expect($rows[0])->toBe(['Invitado', 'Acompañantes', 'Total de personas', 'Confirmados', 'Teléfono', 'Correo', 'Estado', 'Idioma', 'Nota'])
            ->and($rows[1])->toBe(['Ana Pérez', 'Luis Pérez; Marta Pérez', 3, 2, '+13055550101', 'ana@example.com', 'Asistirá', 'Inglés', 'Vegetariana'])
            ->and($rows[2][3])->toBe(0)
            ->and($rows[2][0])->toBe('Beto')
            ->and($rows[2][4])->toBe('no es un teléfono')
            ->and($rows[2][7])->toBe('Español')
            ->and(collect($rows)->contains(fn ($r) => $r[0] === 'Total de personas' && $r[2] === 4))->toBeTrue();
    });

    it('uses the effective language of each guest: hers, or the event\'s', function () {
        [$event, $u, $url] = exportEvent('basic', 'en');
        person($event, 'Ana');
        person($event, 'Beto', 'attending', null, ['locale' => 'es']);

        $rows = rowsOf(sheetOf($this->actingAs($u['owner'])->get("$url/export?locale=en")));

        expect($rows[0][0])->toBe('Guest')
            ->and([$rows[1][7], $rows[2][7]])->toBe(['English', 'Spanish']);
    });

    it('takes the headings from the locale asked for, or from the event', function () {
        [, $u, $url] = exportEvent('basic', 'en');

        expect(rowsOf(sheetOf($this->actingAs($u['owner'])->get("$url/export")))[0][0])->toBe('Guest')
            ->and(rowsOf(sheetOf($this->actingAs($u['owner'])->get("$url/export?locale=es")))[0][0])->toBe('Invitado');
    });

    it('follows the tab and the search of the screen, and never includes other events', function () {
        [$event, $u, $url] = exportEvent();
        [$other] = exportEvent();
        person($event, 'Ana', 'attending');
        person($event, 'Beto', 'pending');
        person($event, 'Carla', 'not-attending');
        person($other, 'Intrusa', 'attending');
        $names = fn (string $query) => collect(rowsOf(sheetOf($this->actingAs($u['owner'])->get("$url/export$query"))))
            ->slice(1)->pluck(0)->filter()->reject(fn ($n) => $n === 'Total de personas')->values()->all();

        expect($names(''))->toBe(['Ana', 'Beto', 'Carla'])
            ->and($names('?status=all'))->toBe(['Ana', 'Beto', 'Carla'])
            ->and($names('?status=pending'))->toBe(['Beto'])
            ->and($names('?status=attending&search=ana'))->toBe(['Ana'])
            ->and($names('?search=zzz'))->toBe([]);
    });

    it('reads a big list in chunks, without a query per family', function () {
        [$event, $u, $url] = exportEvent();
        for ($i = 0; $i < 30; $i++) {
            $family = person($event, sprintf('Familia %02d', $i));
            person($event, "Acompañante $i", 'pending', $family->id);
        }

        DB::enableQueryLog();
        $rows = rowsOf(sheetOf($this->actingAs($u['owner'])->get("$url/export")));
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'from "guests"'));
        DB::disableQueryLog();

        expect(count($rows))->toBeGreaterThanOrEqual(32)
            // main guests + their companions for the one chunk (not one companions query per family)
            ->and($queries->count())->toBeLessThanOrEqual(4);
    });
});

describe('formula injection', function () {
    it('writes everything as text and marks formula-looking text with the quote prefix', function () {
        [$event, $u, $url] = exportEvent();
        $evil = ['=HYPERLINK("http://evil.test","x")', '+SUM(1,1)', '-2+3', '@SUM(1)', "\tTAB", "\rCR"];
        $family = person($event, '=1+1', 'attending', null, ['notes' => '@cmd', 'email' => '+a@b.co']);
        foreach ($evil as $i => $name) {
            person($event, $name, 'pending', $family->id);
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $this->actingAs($u['owner'])->get("$url/export")->baseResponse->getFile()->getContent());
        $sheet = IOFactory::load($path)->getSheet(0);
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        expect($xml)->not->toContain('<f>')
            ->and($sheet->getCell('A2')->getDataType())->toBe('s')
            ->and($sheet->getCell('A2')->getValue())->toBe('=1+1')
            ->and($sheet->getStyle('A2')->getQuotePrefix())->toBeTrue()
            ->and($sheet->getCell('I2')->getDataType())->toBe('s')
            ->and($sheet->getStyle('I2')->getQuotePrefix())->toBeTrue()
            ->and($sheet->getStyle('F2')->getQuotePrefix())->toBeTrue()
            ->and(str_replace(["\r\n", "\r"], "\n", $sheet->getCell('B2')->getValue()))->toBe(str_replace("\r", "\n", implode('; ', $evil)))
            ->and($sheet->getCell('B2')->getDataType())->toBe('s');
    });

    it('also holds in the venue list', function () {
        [$event, $u, $url] = exportEvent('premium');
        person($event, '=cmd|\' /C calc\'!A0', 'attending', null, ['notes' => '+1+1']);

        $sheet = sheetOf($this->actingAs($u['owner'])->get("$url/export/venue"));

        expect($sheet->getCell('B2')->getDataType())->toBe('s')
            ->and($sheet->getStyle('B2')->getQuotePrefix())->toBeTrue();
    });
});

describe('the venue list', function () {
    function venueWorld(Events $event, User $by): array
    {
        $ana = person($event, 'Ana Pérez', 'attending');
        $luis = person($event, 'Luis Pérez', 'attending', $ana->id);
        person($event, 'Zoe', 'pending');
        $declined = person($event, 'Dani', 'not-attending');

        $menu = Menu::create(['event_id' => $event->id, 'title' => 'Principal']);
        $main = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Pollo', 'type' => 'main']);
        $starter = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Ensalada', 'type' => 'starter']);
        $ana->selectedMenuItems()->attach([$main->id, $starter->id]);
        DB::table('guests')->where('id', $luis->id)->update(['allergies' => 'Sin gluten']);

        $table = Table::create(['event_id' => $event->id, 'name' => 'Mesa 1', 'capacity' => 4, 'type' => 'family', 'priority' => 1]);
        TableAssignment::create(['table_id' => $table->id, 'guest_id' => $ana->id, 'seat_number' => '1', 'assigned_by' => $by->id]);
        TableAssignment::create(['table_id' => $table->id, 'guest_id' => $luis->id, 'seat_number' => '2', 'assigned_by' => $by->id]);
        TableAssignment::create(['table_id' => $table->id, 'guest_id' => $declined->id, 'seat_number' => '3', 'assigned_by' => $by->id]);

        return [$ana, $luis];
    }

    it('adds menu, restrictions and table columns when those modules are active', function () {
        [$event, $u, $url] = exportEvent('premium');
        venueWorld($event, $u['owner']);

        $response = $this->actingAs($u['owner'])->get("$url/export/venue");
        $people = rowsOf(sheetOf($response, 0));
        $tables = rowsOf(sheetOf($response, 1));

        expect($response->headers->get('content-disposition'))->toContain('salon-')
            ->and($people[0])->toBe(['N.º', 'Nombre', 'Familia', 'Mesa', 'Asiento', 'Menú elegido', 'Restricciones alimentarias', 'Nota'])
            ->and($people[1])->toBe([1, 'Ana Pérez', 'Ana Pérez', 'Mesa 1', '1', 'Ensalada / Pollo', null, null])
            ->and($people[2])->toBe([2, 'Luis Pérez', 'Ana Pérez', 'Mesa 1', '2', null, 'Sin gluten', null])
            ->and(collect($people)->pluck(1)->all())->not->toContain('Zoe')->not->toContain('Dani')
            ->and(collect($people)->contains(fn ($r) => $r[0] === 'Total de personas' && $r[1] === 2))->toBeTrue()
            ->and($tables[0])->toBe(['Mesa', 'Asiento', 'Nombre', 'Familia', 'Menú elegido', 'Restricciones alimentarias', 'Nota'])
            ->and($tables[1][0])->toBe('Mesa 1')
            ->and($tables[1][2])->toBe('Ana Pérez')
            ->and(collect($tables)->pluck(2)->all())->not->toContain('Dani');
    });

    it('leaves out menu, restrictions and tables when those modules are locked', function () {
        [$event, $u, $url] = exportEvent('basic');
        venueWorld($event, $u['owner']);

        $response = $this->actingAs($u['owner'])->get("$url/export/venue");
        $book = (function () use ($response) {
            $path = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($path, $response->baseResponse->getFile()->getContent());
            $book = IOFactory::load($path);
            @unlink($path);

            return $book;
        })();
        $rows = rowsOf($book->getSheet(0));

        expect($book->getSheetCount())->toBe(1)
            ->and($rows[0])->toBe(['N.º', 'Nombre', 'Familia', 'Nota'])
            ->and($rows[1])->toBe([1, 'Ana Pérez', 'Ana Pérez', null])
            ->and(json_encode($rows))->not->toContain('Pollo')->not->toContain('Sin gluten')->not->toContain('Mesa 1');
    });

    it('can include people who have not answered or declined', function () {
        [$event, $u, $url] = exportEvent('basic');
        venueWorld($event, $u['owner']);

        $rows = rowsOf(sheetOf($this->actingAs($u['owner'])->get("$url/export/venue?scope=all&locale=en")));

        expect($rows[0][1])->toBe('Name')
            ->and(collect($rows)->pluck(1)->filter()->values()->all())->toBe(['Name', 'Ana Pérez', 'Dani', 'Luis Pérez', 'Zoe', 2 + 2]);
    });

    it('does not make a query per person', function () {
        [$event, $u, $url] = exportEvent('premium');
        $table = Table::create(['event_id' => $event->id, 'name' => 'Mesa 1', 'capacity' => 100, 'type' => 'general', 'priority' => 1]);
        $menu = Menu::create(['event_id' => $event->id, 'title' => 'Principal']);
        $item = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Pollo', 'type' => 'main']);
        for ($i = 0; $i < 25; $i++) {
            $g = person($event, sprintf('Invitado %02d', $i));
            $g->selectedMenuItems()->attach($item->id);
            TableAssignment::create(['table_id' => $table->id, 'guest_id' => $g->id, 'seat_number' => (string) ($i + 1), 'assigned_by' => $u['owner']->id]);
        }

        DB::enableQueryLog();
        $this->actingAs($u['owner'])->get("$url/export/venue")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($count)->toBeLessThan(40);
    });
});
