<?php

use App\Exports\GuestMenuExport;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** Two menus; 6 guests: 3 chose menu A, 1 chose menu B, 2 chose nothing. */
function summaryFixture(): array
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
    $users['stranger'] = User::factory()->create();

    $a = Menu::factory()->create(['event_id' => $event->id, 'title' => 'Clasico', 'is_default' => true]);
    $b = Menu::factory()->create(['event_id' => $event->id, 'title' => 'Vegano', 'is_default' => false]);
    Menu::factory()->create(['event_id' => $event->id, 'title' => 'Infantil', 'is_default' => false]);
    $dish = fn (Menu $m, string $name, string $type) => MenuItem::create(['menu_id' => $m->id, 'name' => $name, 'type' => $type]);
    $d = [
        'a_s1' => $dish($a, 'Ensalada', 'starter'), 'a_s2' => $dish($a, 'Sopa', 'starter'),
        'a_m1' => $dish($a, 'Pollo', 'main'), 'a_d1' => $dish($a, 'Flan', 'dessert'),
        'b_m1' => $dish($b, 'Tofu', 'main'),
    ];

    $guests = Guest::factory()->count(6)->sequence(...array_map(fn ($n) => ['name' => "Guest $n"], range(1, 6)))
        ->create(['event_id' => $event->id, 'parent_id' => null, 'rsvp_status' => 'attending']);
    $guests[0]->selectedMenuItems()->sync([$d['a_s1']->id, $d['a_m1']->id, $d['a_d1']->id]);
    $guests[1]->selectedMenuItems()->sync([$d['a_s1']->id, $d['a_m1']->id]);
    $guests[2]->selectedMenuItems()->sync([$d['a_s2']->id]);
    $guests[3]->selectedMenuItems()->sync([$d['b_m1']->id]);

    // Declined people are out of every count: one with an old dish, one without.
    Guest::factory()->create(['event_id' => $event->id, 'name' => 'Declined A', 'rsvp_status' => 'not-attending'])->selectedMenuItems()->sync([$d['a_m1']->id]);
    Guest::factory()->create(['event_id' => $event->id, 'name' => 'Declined B', 'rsvp_status' => 'not-attending']);

    // Another event's choices must never leak in.
    $other = Events::factory()->create();
    $om = Menu::factory()->create(['event_id' => $other->id]);
    Guest::factory()->create(['event_id' => $other->id, 'rsvp_status' => 'attending'])->selectedMenuItems()->sync([MenuItem::create(['menu_id' => $om->id, 'name' => 'X', 'type' => 'main'])->id]);

    return [$event, $users, "/api/v1/app/event/{$event->id}/menus", $a, $b, $d, $guests];
}

it('counts everybody: who chose, per menu and per dish, most chosen first', function () {
    [, $u, $url, $a, $b, $d] = summaryFixture();

    $json = $this->actingAs($u['owner'])->getJson("$url/summary")->assertOk()->json();

    expect($json['total'])->toBe(6)->and($json['responded'])->toBe(4)->and($json['pending'])->toBe(2)
        ->and($json['menus'])->toBe([
            ['menuId' => $a->id, 'name' => 'Clasico', 'count' => 3],
            ['menuId' => $b->id, 'name' => 'Vegano', 'count' => 1],
            ['menuId' => $json['menus'][2]['menuId'], 'name' => 'Infantil', 'count' => 0],
        ])
        ->and($json['courses']['starter'])->toBe([
            ['dishId' => $d['a_s1']->id, 'name' => 'Ensalada', 'count' => 2],
            ['dishId' => $d['a_s2']->id, 'name' => 'Sopa', 'count' => 1],
        ])
        ->and($json['courses']['main'])->toBe([
            ['dishId' => $d['a_m1']->id, 'name' => 'Pollo', 'count' => 2],
            ['dishId' => $d['b_m1']->id, 'name' => 'Tofu', 'count' => 1],
        ])
        ->and($json['courses']['dessert'])->toBe([['dishId' => $d['a_d1']->id, 'name' => 'Flan', 'count' => 1]]);
});

it('answers zeros and empty courses for an event without menus', function () {
    [, $u, $url] = summaryFixture();
    $empty = Events::factory()->draft()->create(['event_type_id' => EventType::where('slug', 'quinceanera')->value('id'), 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id')]);
    EventUserRole::create(['event_id' => $empty->id, 'user_id' => $u['owner']->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    $this->actingAs($u['owner'])->getJson("/api/v1/app/event/{$empty->id}/menus/summary")->assertOk()
        ->assertExactJson(['total' => 0, 'responded' => 0, 'pending' => 0, 'menus' => [], 'courses' => ['starter' => [], 'main' => [], 'dessert' => []]]);
});

it('gives owner, editor and viewer the summary and keeps strangers out', function () {
    [, $u, $url] = summaryFixture();

    foreach (['owner', 'editor', 'viewer'] as $role) {
        $this->actingAs($u[$role])->getJson("$url/summary")->assertOk();
    }
    $this->actingAs($u['stranger'])->getJson("$url/summary")->assertNotFound();
});

it('derives the chosen menu of each row and filters by status keeping the pagination right', function () {
    [, $u, $url, $a, $b] = summaryFixture();

    $all = $this->actingAs($u['owner'])->getJson("$url/guests?per_page=100")->assertOk()->json('data');
    expect($all)->toHaveCount(6)
        ->and($all[0])->toMatchArray(['name' => 'Guest 1', 'menuId' => $a->id, 'menuName' => 'Clasico', 'hasChosen' => true])
        ->and($all[3])->toMatchArray(['menuId' => $b->id, 'menuName' => 'Vegano'])
        ->and($all[4])->toMatchArray(['menuId' => null, 'menuName' => null, 'hasChosen' => false]);

    $pending = $this->actingAs($u['owner'])->getJson("$url/guests?status=pending&per_page=1&page=2")->assertOk();
    expect($pending->json('meta.total'))->toBe(2)->and($pending->json('meta.last_page'))->toBe(2)
        ->and($pending->json('data.0.name'))->toBe('Guest 6');

    $chosen = $this->actingAs($u['owner'])->getJson("$url/guests?status=chosen&per_page=3")->assertOk();
    expect($chosen->json('meta.total'))->toBe(4)->and(collect($chosen->json('data'))->pluck('hasChosen')->unique()->all())->toBe([true]);

    $this->actingAs($u['owner'])->getJson("$url/guests?status=nope")->assertStatus(422)->assertJsonValidationErrors('status');
});

it('keeps the list behind the same permissions', function () {
    [, $u, $url] = summaryFixture();

    $this->actingAs($u['viewer'])->getJson("$url/guests?status=pending")->assertOk();
    $this->actingAs($u['stranger'])->getJson("$url/guests?status=pending")->assertNotFound();
});

it('adds a summary sheet by menu and dish after the guest list', function () {
    [$event] = summaryFixture();

    $path = tempnam(sys_get_temp_dir(), 'menu') . '.xlsx';
    file_put_contents($path, Excel::raw(new GuestMenuExport($event), ExcelWriter::XLSX));
    $book = IOFactory::load($path);
    @unlink($path);

    expect(collect($book->getSheet(0)->toArray())->flatten()->implode('|'))->not->toContain('Declined');
    expect($book->getSheetNames())->toBe(['Guests', 'Summary'])
        ->and($book->getSheet(0)->getHighestRow())->toBe(7);

    $flat = collect($book->getSheet(1)->toArray())->map(fn ($r) => implode('|', array_map('strval', $r)))->implode("\n");
    expect($flat)->toContain('6|4|2')->toContain('Clasico|3')->toContain('Vegano|1')->toContain('Infantil|0')
        ->toContain('Starter|Ensalada|2')->toContain('Main Course|Pollo|2')->toContain('Dessert|Flan|1');
});
