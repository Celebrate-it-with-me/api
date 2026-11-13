<?php

use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Timeline;
use App\Models\User;
use App\Support\PhoneNumber;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function importEvent(string $status = 'draft'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
        'status' => $status,
        'locale' => 'es',
    ]);
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/roster/import"];
}

function csv(string $content, string $name = 'invitados.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

describe('phone numbers', function () {
    it('normalizes what people type to E.164 and says when it assumed the U.S.', function () {
        expect(PhoneNumber::normalize('+1 (305) 555-0101'))->toBe(['e164' => '+13055550101', 'status' => 'ok'])
            ->and(PhoneNumber::normalize('305 555 0101'))->toBe(['e164' => '+13055550101', 'status' => 'assumed_us'])
            ->and(PhoneNumber::normalize('1-305-555-0101'))->toBe(['e164' => '+13055550101', 'status' => 'ok'])
            ->and(PhoneNumber::normalize('+52 55 1234 5678'))->toBe(['e164' => '+525512345678', 'status' => 'ok'])
            ->and(PhoneNumber::normalize('0052 55 1234 5678'))->toBe(['e164' => '+525512345678', 'status' => 'ok'])
            ->and(PhoneNumber::normalize('13055550101.0'))->toBe(['e164' => '+13055550101', 'status' => 'ok'])
            ->and(PhoneNumber::normalize(''))->toBe(['e164' => null, 'status' => 'empty'])
            ->and(PhoneNumber::normalize('555-0101')['status'])->toBe('invalid')
            ->and(PhoneNumber::normalize('abc')['status'])->toBe('invalid')
            ->and(PhoneNumber::normalize('+123')['status'])->toBe('invalid');
    });
});

describe('preview', function () {
    it('reads a Spanish CSV (comma or semicolon, with accents in the headers) and judges each row', function () {
        [, $u, $url] = importEvent();
        $file = csv("Nombre;Teléfono;Correo\nAna Pérez;305 555 0101;ana@example.com\nLuis;abc;no-es-correo\n;;\n");

        $data = $this->actingAs($u['owner'])->post("$url/preview", ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');

        expect($data['rows'])->toHaveCount(2)
            ->and($data['rows'][0])->toMatchArray(['name' => 'Ana Pérez', 'phone' => '+13055550101', 'phoneNote' => 'assumed_us', 'line' => 2, 'problems' => []])
            ->and($data['rows'][1]['problems'])->toBe(['phone_invalid', 'email_invalid'])
            ->and($data['summary'])->toMatchArray(['total' => 2, 'ready' => 1, 'withProblems' => 1, 'assumedUs' => 1, 'duplicates' => 0]);
    });

    it('reads an Excel file and English headers', function () {
        [, $u, $url] = importEvent();
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['Name', 'Phone', 'Email', 'Family'], ['Ana', '+1 305 555 0101', '', 'Pérez'], ['Luis', '', 'luis@example.com', 'Pérez']]);
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new Xlsx($sheet))->save($path);
        $file = new UploadedFile($path, 'guests.xlsx', null, null, true);

        $data = $this->actingAs($u['owner'])->post("$url/preview", ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');

        expect($data['rows'])->toHaveCount(2)->and($data['summary']['hasFamilies'])->toBe(1)->and($data['rows'][1]['family'])->toBe('Pérez');
    });

    it('flags duplicates inside the file and against the guests already on the list', function () {
        [$event, $u, $url] = importEvent();
        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'phone' => '+13055550101', 'email' => 'viejo@example.com']);
        $file = csv("Nombre,Teléfono,Correo\nYa está,305 555 0101,\nNuevo,786 555 0102,\nRepetido,(786) 555-0102,\nMismo correo,,viejo@example.com\n");

        $data = $this->actingAs($u['owner'])->post("$url/preview", ['file' => $file], ['Accept' => 'application/json'])->json('data');

        expect(collect($data['rows'])->pluck('duplicate')->all())->toBe([true, false, true, true])
            ->and($data['summary']['duplicates'])->toBe(3);
    });

    it('rejects a file without a name column, an empty file and a file that is not a spreadsheet', function () {
        [, $u, $url] = importEvent();
        $post = fn ($file) => $this->actingAs($u['owner'])->post("$url/preview", ['file' => $file], ['Accept' => 'application/json']);

        $post(csv("Teléfono,Correo\n305 555 0101,a@b.co\n"))->assertStatus(422)->assertJsonPath('errors.file.0', 'missing_name_column');
        $post(csv(''))->assertStatus(422)->assertJsonPath('errors.file.0', 'empty_file');
        $post(UploadedFile::fake()->create('foto.png', 10, 'image/png'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($u['owner'])->post("$url/preview", [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    });

    it('serves a template with the headers in the event language', function () {
        [, $u, $url] = importEvent();

        $response = $this->actingAs($u['owner'])->get("$url/template")->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('text/csv')->and($response->getContent())->toContain('Nombre,Teléfono,Correo,Familia');
    });
});

describe('import', function () {
    it('creates main guests with access codes, grouping a family into one main guest and companions', function () {
        [$event, $u, $url] = importEvent();
        $rows = [
            ['name' => 'Ana Pérez', 'phone' => '+13055550101', 'family' => 'Pérez'],
            ['name' => 'Luis Pérez', 'family' => 'Pérez'],
            ['name' => 'Marta Gómez', 'phone' => '(786) 555-0102'],
        ];

        $this->actingAs($u['owner'])->postJson($url, ['rows' => $rows])->assertCreated()->assertJsonPath('data', ['created' => 3, 'families' => 2, 'skipped' => 0]);

        $ana = Guest::where('name', 'Ana Pérez')->firstOrFail();
        $luis = Guest::where('name', 'Luis Pérez')->firstOrFail();
        $marta = Guest::where('name', 'Marta Gómez')->firstOrFail();
        expect($ana->parent_id)->toBeNull()->and($ana->code)->not->toBeNull()
            ->and($luis->parent_id)->toBe($ana->id)->and($luis->code)->toBeNull()
            ->and($marta->phone)->toBe('+17865550102')->and($marta->rsvp_status)->toBe('pending');
    });

    it('skips rows with problems and phones already on the list, and creates the rest', function () {
        [$event, $u, $url] = importEvent();
        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'phone' => '+13055550101']);

        $response = $this->actingAs($u['owner'])->postJson($url, ['rows' => [
            ['name' => 'Repetido', 'phone' => '305 555 0101'],
            ['name' => '', 'phone' => '786 555 0102'],
            ['name' => 'Mal teléfono', 'phone' => 'abc'],
            ['name' => 'Bien', 'phone' => '786 555 0103'],
        ]])->assertCreated();

        expect($response->json('data'))->toBe(['created' => 1, 'families' => 1, 'skipped' => 3])
            ->and(Guest::where('event_id', $event->id)->count())->toBe(2);
    });

    it('respects the plan limit once the event is published, and refuses the whole import', function () {
        [$event, $u, $url] = importEvent('published');
        Guest::factory()->count(29)->create(['event_id' => $event->id, 'parent_id' => null]);
        $rows = [['name' => 'Uno'], ['name' => 'Dos']];

        $this->actingAs($u['owner'])->postJson($url, ['rows' => $rows])->assertStatus(402)->assertJsonPath('code', 'guest_limit_exceeded');
        expect(Guest::where('event_id', $event->id)->count())->toBe(29);
    });

    it('lets a draft go over the limit (the list is free until it is published)', function () {
        [$event, $u, $url] = importEvent('draft');
        Guest::factory()->count(29)->create(['event_id' => $event->id, 'parent_id' => null]);

        $this->actingAs($u['owner'])->postJson($url, ['rows' => [['name' => 'Uno'], ['name' => 'Dos'], ['name' => 'Tres']]])->assertCreated();
        expect(Guest::where('event_id', $event->id)->count())->toBe(32);
    });

    it('validates the request', function () {
        [, $u, $url] = importEvent();

        $this->actingAs($u['owner'])->postJson($url, [])->assertJsonValidationErrors('rows');
        $this->actingAs($u['owner'])->postJson($url, ['rows' => []])->assertJsonValidationErrors('rows');
        $this->actingAs($u['owner'])->postJson($url, ['rows' => array_fill(0, 1001, ['name' => 'x'])])->assertJsonValidationErrors('rows');
    });

    it('refreshes the counters the guests screen reads', function () {
        [$event, $u, $url] = importEvent();
        $api = "/api/v1/app/event/{$event->id}";

        $this->actingAs($u['owner'])->postJson($url, ['rows' => [['name' => 'Ana']]])->assertCreated();

        expect($this->actingAs($u['owner'])->getJson("$api/roster")->json('counts.all'))->toBe(1);
    });
});

describe('access', function () {
    it('keeps viewers and strangers out', function () {
        [, $u, $url] = importEvent();

        $this->actingAs($u['viewer'])->get("$url/template")->assertOk();
        $this->actingAs($u['viewer'])->postJson($url, ['rows' => [['name' => 'X']]])->assertForbidden();
        $this->actingAs($u['viewer'])->post("$url/preview", ['file' => csv("Nombre\nA\n")], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u['stranger'])->postJson($url, ['rows' => [['name' => 'X']]])->assertNotFound();
        expect(Guest::count())->toBe(0);
    });
});

describe('onboarding summary', function () {
    it('completes each step from the real data', function () {
        [$event, $u] = importEvent();
        $url = "/api/v1/app/event/{$event->id}/onboarding";

        $first = $this->actingAs($u['owner'])->getJson($url)->assertOk()->json('data');
        expect($first['steps'])->toBe(['event' => true, 'guests' => false, 'details' => false, 'share' => false])
            ->and($first['done'])->toBe(1)->and($first['total'])->toBe(4);

        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null]);
        Timeline::create(['event_id' => $event->id, 'title' => 'Misa', 'type' => 'ceremony', 'start_time' => now()]);
        $event->update(['status' => 'published']);

        $second = $this->actingAs($u['owner'])->getJson($url)->json('data');
        expect($second['steps'])->toBe(['event' => true, 'guests' => true, 'details' => true, 'share' => true])
            ->and($second['done'])->toBe(4)->and($second['guests'])->toBe(1)->and($second['details']['program'])->toBeTrue();
    });

    it('is closed to strangers', function () {
        [$event, $u] = importEvent();

        $this->actingAs($u['stranger'])->getJson("/api/v1/app/event/{$event->id}/onboarding")->assertNotFound();
        $this->actingAs($u['viewer'])->getJson("/api/v1/app/event/{$event->id}/onboarding")->assertOk();
    });
});

it('tells a new event creator they are its owner in the create answer', function () {
    $user = User::factory()->create();
    $user->assignRole('owner');

    $response = $this->actingAs($user)->postJson('/api/v1/app/event', [
        'eventType' => EventType::where('slug', 'quinceanera')->value('id'),
        'eventName' => 'Los XV de Prueba',
        'startDate' => '12/01/2027 18:00',
        'endDate' => '12/01/2027 23:00',
        'customUrlSlug' => 'xv-de-prueba',
    ]);

    expect($response->getStatusCode())->toBeIn([200, 201])->and($response->json('data.userRole'))->toBe('owner');
});
