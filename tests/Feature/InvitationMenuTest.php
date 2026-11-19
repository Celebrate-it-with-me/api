<?php

use App\Exports\GuestMenuExport;
use App\Exports\Roster\VenueExport;
use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\Invitation\InvitationMenu;
use App\Http\Services\AppServices\InvitationService;
use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\Menu;
use App\Models\MenuItem;
use Database\Seeders\EventPlanSeeder;
use Database\Seeders\InvitationTemplateSeeder;
use Database\Seeders\ModuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

const MENU_URL = '/api/v1/app/invitation/xv-de-valentina/AB1234';

/**
 * A premium invitation-ready event with a main guest and two companions, and a default menu with two starters,
 * two mains and a dessert.
 *
 * @return array{0: Events, 1: Guest, 2: Collection, 3: Menu, 4: array<string, MenuItem>}
 */
function menuFixture(string $plan = 'premium'): array
{
    $type = EventType::factory()->create(['slug' => 'quinceanera', 'name' => 'Quinceanera']);
    test()->seed(InvitationTemplateSeeder::class);
    test()->seed([EventPlanSeeder::class, ModuleSeeder::class]);

    $event = Events::factory()->create([
        'event_type_id' => $type->id,
        'event_name' => 'Los XV de Valentina',
        'custom_url_slug' => 'xv-de-valentina',
        'status' => 'published',
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);

    $main = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Rosa', 'code' => 'AB1234', 'rsvp_status' => 'pending', 'parent_id' => null]);
    $companions = Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => $main->id, 'rsvp_status' => 'pending']);

    $menu = Menu::factory()->create(['event_id' => $event->id, 'title' => 'Menu', 'is_default' => true]);
    $items = [];
    foreach ([['s1', 'starter'], ['s2', 'starter'], ['m1', 'main'], ['m2', 'main'], ['d1', 'dessert']] as [$key, $kind]) {
        $items[$key] = MenuItem::create(['menu_id' => $menu->id, 'name' => strtoupper($key), 'type' => $kind, 'diet_type' => $key === 'm2' ? 'vegan' : null]);
    }

    return [$event, $main, $companions, $menu, $items];
}

/** @return array<string, mixed> A deadline that already passed. */
function menuDeadline(Events $event): array
{
    return [
        'event_id' => $event->id, 'title' => 'RSVP', 'description' => '', 'custom_fields' => json_encode([]),
        'confirmation_deadline' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
    ];
}

function menuRsvp(array $body)
{
    return test()->postJson(MENU_URL . '/rsvp', $body);
}

function chosenIds(Guest $guest): array
{
    return DB::table('guest_menu')->where('guest_id', $guest->id)->orderBy('menu_item_id')->pluck('menu_item_id')->map(fn ($id) => (int) $id)->all();
}

describe('the menu in the rsvp data', function () {
    it('sends the menu and each attendee\'s choices', function () {
        [, $main, $companions, , $items] = menuFixture();
        $main->selectedMenuItems()->sync([$items['s1']->id]);

        $data = collect($this->getJson(MENU_URL)->assertOk()->json('data.sections'))->firstWhere('type', 'rsvp')['data'];

        expect($data['menu']['title'])->toBe('Menu')
            ->and($data['menu']['allowMultiple'])->toBeFalse()
            ->and($data['menu']['editable'])->toBeTrue()
            ->and(array_column($data['menu']['items'], 'name'))->toBe(['S1', 'S2', 'M1', 'M2', 'D1'])
            ->and($data['menu']['items'][3])->toMatchArray(['type' => 'main', 'dietType' => 'vegan', 'imageUrl' => null])
            ->and($data['guest']['menuItemIds'])->toBe([$items['s1']->id])
            ->and($data['companions'][0])->toMatchArray(['id' => $companions[0]->id, 'menuItemIds' => []]);
    });

    it('turns a dish picture into a url, keeping absolute ones as they are', function () {
        [, , , , $items] = menuFixture();
        $items['s1']->update(['image_path' => 'menus/s1.jpg']);
        $items['s2']->update(['image_path' => 'https://cdn.test/s2.jpg']);
        $items['m1']->update(['image_path' => '']);

        $data = collect($this->getJson(MENU_URL)->json('data.sections'))->firstWhere('type', 'rsvp')['data'];
        $urls = collect($data['menu']['items'])->pluck('imageUrl', 'name');

        expect($urls['S1'])->toEndWith('menus/s1.jpg')->and($urls['S2'])->toBe('https://cdn.test/s2.jpg')->and($urls['M1'])->toBeNull();
    });

    it('resolves assigned menu, then the default, then the only menu', function () {
        [$event, $main, , $default] = menuFixture();
        $other = Menu::factory()->create(['event_id' => $event->id, 'title' => 'Kids']);
        MenuItem::create(['menu_id' => $other->id, 'name' => 'Nuggets', 'type' => 'main']);
        $service = app(InvitationMenu::class);

        expect($service->resolve($event, $main)->id)->toBe($default->id);

        $main->update(['assigned_menu_id' => $other->id]);
        expect($service->resolve($event, $main->fresh())->id)->toBe($other->id);

        // An assigned menu of another event is never offered.
        $foreign = Menu::factory()->create();
        $main->update(['assigned_menu_id' => $foreign->id]);
        expect($service->resolve($event, $main->fresh())->id)->toBe($default->id);

        // No default and two menus: ambiguous, no step. One menu alone: that one.
        $default->update(['is_default' => false]);
        expect($service->resolve($event, $main->fresh()))->toBeNull();
        $other->delete();
        expect($service->resolve($event, $main->fresh())->id)->toBe($default->id);
    });

    it('sends nothing when the module is locked for this event by an exception', function () {
        [$event] = menuFixture();
        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'menus', 'state' => 'locked', 'reason' => 'test']);
        app(EventModules::class)->invalidate();

        $rsvp = collect($this->getJson(MENU_URL)->json('data.sections'))->firstWhere('type', 'rsvp')['data'];

        expect($rsvp)->not->toHaveKey('menu')->and($rsvp['guest'])->not->toHaveKey('menuItemIds');
    });

    it('does not offer a menu without dishes or without any menu', function () {
        [$event, $main, , $menu] = menuFixture();
        $service = app(InvitationMenu::class);

        MenuItem::where('menu_id', $menu->id)->delete();
        expect($service->resolve($event, $main))->toBeNull();

        Menu::where('event_id', $event->id)->delete();
        expect($service->resolve($event, $main))->toBeNull();
    });

    it('is not offered on the entry plan, where the menus module is locked', function () {
        [$event] = menuFixture('basic');

        $rsvp = collect($this->getJson(MENU_URL)->json('data.sections'))->firstWhere('type', 'rsvp')['data'];

        expect($rsvp)->not->toHaveKey('menu');
        $this->postJson(MENU_URL . '/rsvp', ['guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [999]]])->assertOk();
        expect(DB::table('guest_menu')->count())->toBe(0);
    });

    it('is read only once the deadline has passed', function () {
        [$event] = menuFixture();
        DB::table('rsvps')->insert([
            ...menuDeadline($event),
        ]);

        $rsvp = collect($this->getJson(MENU_URL)->json('data.sections'))->firstWhere('type', 'rsvp')['data'];

        expect($rsvp['menu']['editable'])->toBeFalse();
    });

    it('keeps the menu on a section a template package draws', function () {
        [, $main] = menuFixture();
        $sections = $this->getJson(MENU_URL)->json('data.sections');
        $rsvpSection = collect($sections)->firstWhere('type', 'rsvp');

        $drawn = app(InvitationService::class)->attachPackage([$rsvpSection], ['rsvp' => ['ast']]);

        expect($drawn[0]['render'])->toBe('package')->and($drawn[0]['data'])->toHaveKey('menu');
    });
});

describe('saving the menu with the rsvp', function () {
    it('lets a guest with two companions choose, change and see what was saved', function () {
        [, $main, $companions, , $items] = menuFixture();

        $first = menuRsvp([
            'guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['s1']->id, $items['m1']->id, $items['d1']->id]],
            'companions' => [
                ['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'menuItemIds' => [$items['m2']->id]],
                ['id' => $companions[1]->id, 'rsvpStatus' => 'attending', 'menuItemIds' => []],
            ],
        ])->assertOk();

        expect($first->json('data.guest.menuItemIds'))->toBe([$items['s1']->id, $items['m1']->id, $items['d1']->id])
            ->and($first->json('data.companions.0.menuItemIds'))->toBe([$items['m2']->id])
            ->and($first->json('data.companions.1.menuItemIds'))->toBe([]);

        menuRsvp([
            'guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['s2']->id]],
            'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'menuItemIds' => [$items['m1']->id, $items['s1']->id]]],
        ])->assertOk();

        expect(chosenIds($main))->toBe([$items['s2']->id])
            ->and(chosenIds($companions[0]))->toBe([$items['s1']->id, $items['m1']->id])
            ->and(DB::table('guest_menu')->count())->toBe(3);

        // Re-opening the invitation shows the saved choices per person.
        $data = collect($this->getJson(MENU_URL)->json('data.sections'))->firstWhere('type', 'rsvp')['data'];
        expect($data['guest']['menuItemIds'])->toBe([$items['s2']->id]);
    });

    it('works for older clients that send no dishes: nothing changes', function () {
        [, $main, $companions, , $items] = menuFixture();
        $main->selectedMenuItems()->sync([$items['m1']->id]);

        menuRsvp(['guest' => ['rsvpStatus' => 'attending'], 'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending']]])->assertOk();

        expect(chosenIds($main))->toBe([$items['m1']->id])->and($main->fresh()->rsvp_status)->toBe('attending');
    });

    it('empties the choice when an empty list is sent', function () {
        [, $main, , , $items] = menuFixture();
        $main->selectedMenuItems()->sync([$items['m1']->id]);

        menuRsvp(['guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => []]])->assertOk();

        expect(chosenIds($main))->toBe([]);
    });

    it('drops the choices of whoever declines, and of all the companions when the main guest declines', function () {
        [, $main, $companions, , $items] = menuFixture();
        $main->selectedMenuItems()->sync([$items['m1']->id]);
        $companions->each(fn ($c) => $c->selectedMenuItems()->sync([$items['d1']->id]));
        $companions->each->update(['rsvp_status' => 'attending']);

        menuRsvp([
            'guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['m1']->id]],
            'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'not-attending', 'menuItemIds' => [$items['d1']->id]]],
        ])->assertOk();

        expect(chosenIds($companions[0]))->toBe([])->and(chosenIds($companions[1]))->toBe([$items['d1']->id]);

        menuRsvp(['guest' => ['rsvpStatus' => 'not-attending', 'menuItemIds' => [$items['m1']->id]]])->assertOk();

        expect(chosenIds($main))->toBe([])->and(chosenIds($companions[1]))->toBe([]);
    });

    it('clears the choices of a companion who is still pending when the guest saves', function () {
        [, , $companions, , $items] = menuFixture();
        $companions[1]->selectedMenuItems()->sync([$items['m1']->id]);

        menuRsvp(['guest' => ['rsvpStatus' => 'attending']])->assertOk();

        expect(chosenIds($companions[1]))->toBe([]);
    });

    it('refuses dishes that are not on the offered menu and writes nothing', function () {
        [$event, $main, $companions, , $items] = menuFixture();
        $foreignItem = MenuItem::create(['menu_id' => Menu::factory()->create()->id, 'name' => 'Foreign', 'type' => 'main']);
        $otherMenu = Menu::factory()->create(['event_id' => $event->id]);
        $sameEventOther = MenuItem::create(['menu_id' => $otherMenu->id, 'name' => 'Other', 'type' => 'main']);

        foreach ([$foreignItem->id, $sameEventOther->id, 987654] as $bad) {
            menuRsvp(['guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$bad]]])
                ->assertUnprocessable()->assertJsonValidationErrors('guest.menuItemIds');
        }

        menuRsvp([
            'guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['m1']->id]],
            'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'menuItemIds' => [$foreignItem->id]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('companions.0.menuItemIds');

        expect(DB::table('guest_menu')->count())->toBe(0)->and($main->fresh()->rsvp_status)->toBe('pending');
    });

    it('refuses more than one dish of a type', function () {
        [, $main, $companions, , $items] = menuFixture();

        menuRsvp(['guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['s1']->id, $items['s2']->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('guest.menuItemIds');

        menuRsvp([
            'guest' => ['rsvpStatus' => 'attending'],
            'companions' => [['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'menuItemIds' => [$items['m1']->id, $items['m2']->id]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('companions.0.menuItemIds');

        expect(DB::table('guest_menu')->count())->toBe(0);
    });

    it('refuses a malformed list of dishes', function () {
        [, , , , $items] = menuFixture();
        $id = $items['m1']->id;

        foreach (['x', [$id, $id], ['a'], range(1, 11)] as $bad) {
            $errors = menuRsvp(['guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => $bad]])->assertUnprocessable()->json('errors');
            expect(array_keys($errors))->each->toStartWith('guest.menuItemIds');
        }
    });

    it('refuses everything once the rsvp window is closed', function () {
        [$event, $main, , , $items] = menuFixture();
        DB::table('rsvps')->insert(menuDeadline($event));

        menuRsvp(['guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['m1']->id]]])->assertUnprocessable()->assertJsonValidationErrors('rsvp');

        expect(chosenIds($main))->toBe([]);
    });

    it('ignores what is received when the menus module is not active', function () {
        [, $main, , , $items] = menuFixture('basic');

        menuRsvp(['guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['m1']->id, $items['m2']->id]]])->assertOk()
            ->assertJsonMissingPath('data.menu');

        expect(chosenIds($main))->toBe([]);
    });

    it('changes the body of the invitation, so its ETag changes', function () {
        [, , , , $items] = menuFixture();
        $before = $this->getJson(MENU_URL)->headers->get('ETag');

        menuRsvp(['guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['m1']->id]]])->assertOk();

        $after = $this->getJson(MENU_URL)->headers->get('ETag');
        expect($after)->not->toBe($before);
        $this->getJson(MENU_URL, ['If-None-Match' => $before])->assertOk();
    });
});

describe('the unique index of guest_menu', function () {
    it('refuses the same dish twice for a guest', function () {
        [, $main, , , $items] = menuFixture();
        DB::table('guest_menu')->insert(['guest_id' => $main->id, 'menu_item_id' => $items['m1']->id]);

        expect(fn () => DB::table('guest_menu')->insert(['guest_id' => $main->id, 'menu_item_id' => $items['m1']->id]))
            ->toThrow(QueryException::class);
    });

    it('removes existing duplicates before indexing and can run twice', function () {
        [, $main, $companions, , $items] = menuFixture();
        $migration = require database_path('migrations/2027_07_10_000001_add_unique_to_guest_menu.php');

        $migration->down();
        foreach ([$main, $main, $main, $companions[0]] as $guest) {
            DB::table('guest_menu')->insert(['guest_id' => $guest->id, 'menu_item_id' => $items['m1']->id]);
        }
        DB::table('guest_menu')->insert(['guest_id' => $main->id, 'menu_item_id' => $items['d1']->id]);
        $oldest = DB::table('guest_menu')->where('guest_id', $main->id)->where('menu_item_id', $items['m1']->id)->min('id');

        $migration->up();
        $migration->up();

        expect(DB::table('guest_menu')->count())->toBe(3)
            ->and(DB::table('guest_menu')->where('guest_id', $main->id)->where('menu_item_id', $items['m1']->id)->value('id'))->toBe($oldest);

        $migration->down();
        $migration->down();
        $migration->up();
        expect(DB::table('guest_menu')->count())->toBe(3);
    });
});

describe('what the organizer exports', function () {
    it('carries each person\'s dishes to the guest menu export and the venue list', function () {
        [$event, $main, $companions, , $items] = menuFixture();

        menuRsvp([
            'guest' => ['rsvpStatus' => 'attending', 'menuItemIds' => [$items['s1']->id, $items['m1']->id, $items['d1']->id]],
            'companions' => [
                ['id' => $companions[0]->id, 'rsvpStatus' => 'attending', 'menuItemIds' => [$items['m2']->id]],
                ['id' => $companions[1]->id, 'rsvpStatus' => 'attending'],
            ],
        ])->assertOk();

        $load = function (object $export) {
            $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
            file_put_contents($path, Excel::raw($export, ExcelWriter::XLSX));
            $book = IOFactory::load($path);
            @unlink($path);

            return $book;
        };

        $guestSheet = $load(new GuestMenuExport($event))->getActiveSheet()->toArray();
        $rows = collect($guestSheet)->keyBy(0);
        expect($rows[$main->name][2])->toBe('S1')->and($rows[$main->name][3])->toBe('M1')->and($rows[$main->name][4])->toBe('D1')
            ->and($rows[$companions[0]->name][3])->toBe('M2');

        $people = $load(new VenueExport($event, false, 'es'))->getSheet(0)->toArray();
        $flat = collect($people)->map(fn ($r) => implode('|', array_map('strval', $r)))->implode("\n");
        expect($flat)->toContain('S1 / M1 / D1')->toContain($companions[0]->name)->toContain('M2');
    });
});
