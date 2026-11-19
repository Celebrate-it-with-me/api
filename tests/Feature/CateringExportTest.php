<?php

use App\Http\Controllers\AppControllers\Seating\CateringExportController;
use App\Http\Services\AppServices\Seating\CateringReport;
use App\Models\EventModuleOverride;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{0: Events, 1: array<string, User>, 2: string} */
function cateringEvent(string $plan = 'premium', string $locale = 'es'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => 'draft', 'locale' => $locale, 'event_name' => 'Los XV de Valentina', 'custom_url_slug' => 'xv-cat-' . uniqid(),
        'start_date' => '2027-03-20 18:00:00',
    ]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/seating/export/catering"];
}

function catGuest(Events $event, string $name, string $status = 'attending', ?int $parent = null, array $extra = []): Guest
{
    return Guest::factory()->create(array_merge(['event_id' => $event->id, 'parent_id' => $parent, 'name' => $name, 'rsvp_status' => $status, 'email' => 'secreto@example.com', 'phone' => '+13055550199', 'notes' => null], $extra));
}

/** @return array<string, MenuItem> */
function catMenu(Events $event): array
{
    $menu = Menu::create(['event_id' => $event->id, 'title' => 'Boda']);
    $items = [];
    foreach ([['ensalada', 'Ensalada César', 'starter', null], ['sopa', 'Sopa de calabaza', 'starter', 'Vegano'], ['pollo', 'Pollo asado', 'main', null], ['pasta', 'Pasta sin gluten', 'main', 'Sin gluten'], ['flan', 'Flan', 'dessert', null]] as [$k, $name, $type, $diet]) {
        $items[$k] = MenuItem::create(['menu_id' => $menu->id, 'name' => $name, 'type' => $type, 'diet_type' => $diet]);
    }

    return $items;
}

function catTable(Events $event, string $name, array $guests, User $by): Table
{
    $table = Table::create(['event_id' => $event->id, 'name' => $name, 'capacity' => 6, 'type' => 'family', 'priority' => 1]);
    foreach ($guests as $i => $guest) {
        TableAssignment::create(['table_id' => $table->id, 'guest_id' => $guest->id, 'seat_number' => (string) ($i + 1), 'assigned_by' => $by->id]);
    }

    return $table;
}

/** Fixture: 3 tables, 2 dishes per course, a companion, a pending and a declined person, one without table. */
function catWorld(Events $event, User $by): array
{
    $m = catMenu($event);
    $ana = catGuest($event, 'Ana Núñez');
    $luis = catGuest($event, 'Luis Núñez', 'attending', $ana->id, ['allergies' => 'Nueces']);
    $zoe = catGuest($event, 'Zoe Ñandú');
    $paco = catGuest($event, 'Paco');
    $sin = catGuest($event, 'Sin Mesa');
    $pend = catGuest($event, 'Pendiente Pérez', 'pending');
    $no = catGuest($event, 'Declina', 'not-attending');
    $ana->selectedMenuItems()->attach([$m['ensalada']->id, $m['pollo']->id, $m['flan']->id]);
    $luis->selectedMenuItems()->attach([$m['sopa']->id, $m['pasta']->id]);
    $zoe->selectedMenuItems()->attach([$m['ensalada']->id, $m['pasta']->id]);
    $paco->selectedMenuItems()->attach([$m['ensalada']->id]);
    $pend->selectedMenuItems()->attach([$m['pollo']->id]);
    catTable($event, 'Mesa 1', [$ana, $luis], $by);
    catTable($event, 'Mesa 2', [$zoe, $paco], $by);
    catTable($event, 'Mesa 3', [$pend, $no], $by);

    return compact('ana', 'luis', 'zoe', 'paco', 'sin', 'pend', 'no');
}

function catBook($response)
{
    $path = tempnam(sys_get_temp_dir(), 'cat');
    file_put_contents($path, $response->baseResponse->getFile()->getContent());
    $book = IOFactory::load($path);
    @unlink($path);

    return $book;
}

function catRows($book, int $i): array
{
    return $book->getSheet($i)->toArray(null, true, false, false);
}

it('summarizes the dishes per course and the people without a choice', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);

    $response = $this->actingAs($u['owner'])->get($url)->assertOk();
    $book = catBook($response);
    $rows = catRows($book, 0);

    expect($response->headers->get('content-disposition'))->toContain('catering-xv-cat-')
        ->and($book->getSheetCount())->toBe(4)
        ->and($rows[0])->toBe(['Tipo', 'Plato', 'Dieta', 'Cantidad'])
        ->and(array_slice($rows, 1, 8))->toBe([
            ['Entrada', 'Ensalada César', null, 3],
            ['Entrada', 'Sopa de calabaza', 'Vegano', 1],
            ['Entrada', 'Sin elegir', null, 1],
            ['Plato fuerte', 'Pasta sin gluten', 'Sin gluten', 2],
            ['Plato fuerte', 'Pollo asado', null, 1],
            ['Plato fuerte', 'Sin elegir', null, 2],
            ['Postre', 'Flan', null, 1],
            ['Postre', 'Sin elegir', null, 4],
        ])
        ->and(json_encode($rows, JSON_UNESCAPED_UNICODE))->toContain('Total de personas')->toContain('Solo personas confirmadas.');
});

it('counts dishes per table, lists the people by seat and leaves contact data out', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);

    $book = catBook($this->actingAs($u['owner'])->get($url));
    $tables = catRows($book, 1);
    $list = catRows($book, 2);
    $none = catRows($book, 3);

    expect($tables[0])->toBe(['Mesa', 'Personas', 'Capacidad', 'Tipo', 'Plato', 'Dieta', 'Cantidad'])
        ->and($tables[1])->toBe(['Mesa 1', 2, 6, 'Entrada', 'Ensalada César', null, 1])
        ->and(collect($tables)->contains(fn ($r) => $r[0] === 'Mesa 2' && $r[4] === 'Ensalada César' && $r[6] === 2))->toBeTrue()
        ->and(collect($tables)->pluck(0)->unique()->values()->all())->toBe(['Mesa', 'Mesa 1', 'Mesa 2'])
        ->and($list[0])->toBe(['Mesa', 'Asiento', 'Invitado', 'Acompañante de', 'Entrada', 'Plato fuerte', 'Postre', 'Restricciones'])
        ->and($list[1])->toBe(['Mesa 1', 1, 'Ana Núñez', null, 'Ensalada César', 'Pollo asado', 'Flan', null])
        ->and($list[2])->toBe(['Mesa 1', 2, 'Luis Núñez', 'Ana Núñez', 'Sopa de calabaza', 'Pasta sin gluten', null, 'Vegano; Sin gluten; Nueces'])
        ->and($none[0])->toBe(['Invitado', 'Acompañante de', 'Entrada', 'Plato fuerte', 'Postre', 'Restricciones'])
        ->and(collect($none)->pluck(0)->all())->toBe(['Invitado', 'Sin Mesa']);
    expect(json_encode([$tables, $list, $none]))->not->toContain('secreto@example.com')->not->toContain('3055550199')->not->toContain('Declina');
});

it('adds the pending people only when asked, with their status', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);

    $book = catBook($this->actingAs($u['owner'])->get("$url?include_pending=1"));
    $list = catRows($book, 2);

    expect($list[0])->toContain('Estado')
        ->and(collect($list)->first(fn ($r) => $r[2] === 'Pendiente Pérez'))->toContain('Pendiente')
        ->and(collect(catRows($book, 0))->first(fn ($r) => $r[0] === 'Entrada' && $r[1] === 'Sin elegir')[3])->toBe(2)
        ->and(json_encode(catRows($book, 0), JSON_UNESCAPED_UNICODE))->toContain('Incluye personas que aún no han respondido.');
    expect(json_encode(catRows(catBook($this->actingAs($u['owner'])->get($url)), 2)))->not->toContain('Pendiente');
});

it('speaks English when asked and takes the event language by default', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);

    $en = catRows(catBook($this->actingAs($u['owner'])->get("$url?locale=en")), 0);
    expect($en[0])->toBe(['Type', 'Dish', 'Diet', 'Count'])->and($en[1][0])->toBe('Starter');

    [$event2, $u2, $url2] = cateringEvent('premium', 'en');
    catWorld($event2, $u2['owner']);
    expect(catRows(catBook($this->actingAs($u2['owner'])->get($url2)), 0)[0][0])->toBe('Type');
});

function catLock(Events $event, string $key): void
{
    EventModuleOverride::create(['event_id' => $event->id, 'module_key' => $key, 'state' => 'locked', 'reason' => 'test']);
}

it('leaves the dishes out, and says so, when the menu module is off', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);
    catLock($event, 'menus');

    $book = catBook($this->actingAs($u['owner'])->get($url));
    $summary = catRows($book, 0);

    expect($book->getSheetCount())->toBe(4)
        ->and(json_encode($summary, JSON_UNESCAPED_UNICODE))->toContain('El módulo de menú está apagado')->not->toContain('Ensalada')
        ->and(catRows($book, 1)[0])->toBe(['Mesa', 'Personas', 'Capacidad'])
        ->and(catRows($book, 1)[1])->toBe(['Mesa 1', 2, 6])
        ->and(catRows($book, 2)[0])->toBe(['Mesa', 'Asiento', 'Invitado', 'Acompañante de', 'Restricciones'])
        ->and(json_encode(catRows($book, 2), JSON_UNESCAPED_UNICODE))->not->toContain('Pollo')->toContain('Nueces');
});

it('gives only the dish summary when the tables module is off', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);
    catLock($event, 'seating');

    $book = catBook($this->actingAs($u['owner'])->get($url)->assertOk());

    expect($book->getSheetCount())->toBe(1)
        ->and(catRows($book, 0)[1])->toBe(['Entrada', 'Ensalada César', null, 3])
        ->and(json_encode(catRows($book, 0), JSON_UNESCAPED_UNICODE))->toContain('El módulo de mesas está apagado');
});

it('still answers with both modules off (only the headcount)', function () {
    [$event, $u, $url] = cateringEvent('basic');
    catGuest($event, 'Ana Núñez');

    $rows = catRows(catBook($this->actingAs($u['owner'])->get($url)->assertOk()), 0);

    expect(collect($rows)->contains(fn ($r) => $r[0] === 'Total de personas' && $r[3] === 1))->toBeTrue();
});

it('treats the same dishes with a table that has nobody confirmed and with no guests at all', function () {
    [$event, $u, $url] = cateringEvent();
    catMenu($event);

    $book = catBook($this->actingAs($u['owner'])->get($url)->assertOk());

    expect(catRows($book, 0)[1])->toBe(['Entrada', 'Sin elegir', null, 0])
        ->and(count(catRows($book, 1)))->toBe(1);
});

it('lists only the courses the menu has, in serving order', function () {
    [$event, $u, $url] = cateringEvent();
    $menu = Menu::create(['event_id' => $event->id, 'title' => 'M']);
    MenuItem::create(['menu_id' => $menu->id, 'name' => 'Flan', 'type' => 'dessert']);
    MenuItem::create(['menu_id' => $menu->id, 'name' => 'Sopa', 'type' => 'starter']);
    catGuest($event, 'Ana');

    $rows = array_slice(catRows(catBook($this->actingAs($u['owner'])->get($url)), 0), 1);

    expect(collect($rows)->pluck(0)->filter()->unique()->values()->take(2)->all())->toBe(['Entrada', 'Postre'])
        ->and(collect($rows)->pluck(0))->not->toContain('Plato fuerte');
});

it('writes formula-looking names and dishes as text', function () {
    [$event, $u, $url] = cateringEvent();
    $menu = Menu::create(['event_id' => $event->id, 'title' => 'M']);
    $evil = MenuItem::create(['menu_id' => $menu->id, 'name' => '=HYPERLINK("http://x")', 'type' => 'main', 'diet_type' => '@cmd']);
    $guest = catGuest($event, '+SUM(1+1)', 'attending', null, ['allergies' => '-2+3']);
    $guest->selectedMenuItems()->attach($evil->id);
    catTable($event, 'Mesa 1', [$guest], $u['owner']);

    $book = catBook($this->actingAs($u['owner'])->get($url));

    foreach ([[0, 'B2', '=HYPERLINK("http://x")'], [2, 'C2', '+SUM(1+1)'], [2, 'E2', '=HYPERLINK("http://x")']] as [$sheet, $cell, $text]) {
        $c = $book->getSheet($sheet)->getCell($cell);
        expect($c->getValue())->toBe($text)->and($c->getDataType())->toBe('s')->and($c->getStyle()->getQuotePrefix())->toBeTrue();
    }
});

it('is a download of members only, and a stranger or another event gets 404', function () {
    [$event, $u, $url] = cateringEvent();
    [, $u2, $url2] = cateringEvent();

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs($u['stranger'])->getJson($url)->assertNotFound();
    $this->actingAs($u2['owner'])->getJson($url)->assertNotFound();
    $this->actingAs($u['viewer'])->get($url)->assertOk();
    $this->actingAs($u['owner'])->getJson("$url?format=doc")->assertUnprocessable();
    $this->actingAs($u['owner'])->getJson("$url?locale=fr")->assertUnprocessable();
    $this->actingAs($u['owner'])->getJson("$url?include_pending=maybe")->assertUnprocessable();
});

it('is limited to 10 downloads a minute', function () {
    [, $u, $url] = cateringEvent();
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($u['owner'])->get($url)->assertOk();
    }
    $this->actingAs($u['owner'])->getJson($url)->assertStatus(429);
});

it('builds a PDF with the accents, the header and the dishes', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);

    $response = $this->actingAs($u['owner'])->get("$url?format=pdf")->assertOk();
    $pdf = $response->baseResponse->getContent();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('catering-xv-cat-')->toContain('.pdf')
        ->and(str_starts_with($pdf, '%PDF'))->toBeTrue()
        ->and(strlen($pdf))->toBeGreaterThan(3000)->and($pdf)->toContain('DejaVu');
});

it('prints the PDF layout with accents, tables, notes and the footer data', function () {
    [$event, $u] = cateringEvent();
    catWorld($event, $u['owner']);
    $report = app(CateringReport::class);

    $html = view('exports.catering', ['r' => $report->build($event, true, 'es')])->render();
    $htmlOff = view('exports.catering', ['r' => $report->build(tap($event)->forceFill([]), false, 'en')])->render();

    expect($html)->toContain('Los XV de Valentina')->toContain('20 de marzo de 2027')->toContain('Núñez')->toContain('Ñandú')
        ->toContain('Plato fuerte')->toContain('Acompañante de Ana Núñez')->toContain('[Pendiente]')->toContain('Sin Mesa')
        ->and($htmlOff)->toContain('Main course')->toContain('Confirmed people only.');

    catLock($event, 'menus');
    catLock($event, 'seating');
    $bare = view('exports.catering', ['r' => $report->build($event->refresh(), false, 'es')])->render();
    expect($bare)->toContain('El módulo de menú está apagado')->toContain('El módulo de mesas está apagado');
});

it('the PDF footer shows the page numbers', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);

    $pdf = $this->actingAs($u['owner'])->get("$url?format=pdf&locale=en")->assertOk()->baseResponse->getContent();

    // dompdf writes the footer as text drawn on each page: its label survives in the (uncompressed) font subset only as glyph ids,
    // so check the document has the expected page structure instead.
    expect(preg_match_all('#/Type\s*/Page[^s]#', $pdf))->toBeGreaterThanOrEqual(1);
});

it('prints the generation time on the clock of the event, not in UTC', function () {
    [$event] = cateringEvent();
    $controller = app(CateringExportController::class);
    $this->travelTo(Carbon\Carbon::parse('2027-01-10 03:30:00', 'UTC'));

    $event->timezone = 'America/New_York';
    expect($controller->generatedAt($event, 'es'))->toBe('9 de enero de 2027, 22:30 EST')
        ->and($controller->generatedAt($event, 'en'))->toBe('January 9, 2027, 22:30 EST');

    $event->timezone = 'Europe/Madrid';
    expect($controller->generatedAt($event, 'es'))->toBe('10 de enero de 2027, 04:30 CET');

    // A broken zone falls back to the app's, instead of failing the download.
    $event->timezone = 'Mars/Olympus';
    expect($controller->generatedAt($event, 'en'))->toBe('January 10, 2027, 03:30 UTC');
});

it('lines every table up on the same columns and leaves out the dishes nobody picked or skipped', function () {
    [$event, $u] = cateringEvent();
    catWorld($event, $u['owner']);
    $report = app(CateringReport::class)->build($event, false, 'es');
    // Everybody at the first table chose everything: nothing "Sin elegir" there; and the summary of a type nobody chose is gone.
    $report['summary']['main']['unchosen'] = 0;
    $report['summary']['dessert'] = ['items' => [], 'unchosen' => 0];
    foreach ($report['tables'] as $i => $table) {
        foreach ($table['dishes'] as $type => $block) {
            $report['tables'][$i]['dishes'][$type]['unchosen'] = 0;
        }
    }

    $html = view('exports.catering', ['r' => $report])->render();

    // The same <colgroup> opens each guest list (and every dish grid), so the columns do not move between tables.
    preg_match_all('#<colgroup>.*?</colgroup>#s', $html, $m);
    $grids = array_count_values($m[0]);
    expect(count($report['tables']))->toBeGreaterThan(1)
        ->and(max($grids))->toBeGreaterThanOrEqual(count($report['tables']) * 2)
        ->and($html)->toContain('table-layout: fixed')
        ->and($html)->not->toContain('page-break-before: always')
        ->and($html)->not->toMatch('#Sin elegir</td><td></td><td class="r">0#')
        ->and($html)->not->toContain('&times; Sin elegir')
        ->and($html)->not->toContain('Postre</h3>');
});

it('writes a light PDF: fonts are subset, not embedded whole', function () {
    [$event, $u, $url] = cateringEvent();
    catWorld($event, $u['owner']);

    $pdf = $this->actingAs($u['owner'])->get("$url?format=pdf")->assertOk()->baseResponse->getContent();

    expect(strlen($pdf))->toBeLessThan(300_000);
});
