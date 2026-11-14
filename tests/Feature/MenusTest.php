<?php

use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Menu;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function menuEvent(): array
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

    return [$event, $users, "/api/v1/app/event/{$event->id}/menus"];
}

function menuData(array $extra = []): array
{
    return array_merge(['title' => 'Menú principal', 'description' => 'Cena de tres tiempos', 'allowMultipleChoices' => false, 'allowCustomRequests' => false, 'isDefault' => false], $extra);
}

it('answers an empty list, not a 404, when there are no menus or guests', function () {
    [, $u, $url] = menuEvent();

    $this->actingAs($u['owner'])->getJson($url)->assertOk()->assertExactJson([]);
    $this->actingAs($u['owner'])->getJson("$url/guests")->assertOk()->assertJsonCount(0, 'data');
});

it('saves allowCustomRequests and allowMultipleChoices on create and on update', function () {
    [, $u, $url] = menuEvent();

    $id = $this->actingAs($u['owner'])->postJson($url, menuData(['allowCustomRequests' => true, 'allowMultipleChoices' => true]))->assertCreated()
        ->assertJsonPath('allow_custom_request', true)->assertJsonPath('allow_multiple_choices', true)->json('id');

    $this->actingAs($u['owner'])->putJson("$url/$id", menuData(['title' => 'Cena', 'allowCustomRequests' => true, 'allowMultipleChoices' => false]))->assertOk()
        ->assertJsonPath('title', 'Cena')->assertJsonPath('allow_custom_request', true)->assertJsonPath('allow_multiple_choices', false);
});

it('also takes the singular spelling the old screen sent', function () {
    [, $u, $url] = menuEvent();

    $this->actingAs($u['owner'])->postJson($url, ['title' => 'X', 'allowCustomRequest' => true])->assertCreated()->assertJsonPath('allow_custom_request', true);
});

it('creates a menu without the isDefault field: the first one becomes the main menu', function () {
    [, $u, $url] = menuEvent();

    $first = $this->actingAs($u['owner'])->postJson($url, ['title' => 'Uno'])->assertCreated()->json();
    $second = $this->actingAs($u['owner'])->postJson($url, ['title' => 'Dos'])->assertCreated()->json();

    expect($first['is_default'])->toBeTrue()->and($second['is_default'])->toBeFalse();
});

it('moves the main menu when another one is made main, on create and on update', function () {
    [, $u, $url] = menuEvent();
    $a = $this->actingAs($u['owner'])->postJson($url, menuData(['title' => 'A', 'isDefault' => true]))->json('id');
    $b = $this->actingAs($u['owner'])->postJson($url, menuData(['title' => 'B', 'isDefault' => true]))->json('id');
    expect(Menu::find($a)->is_default)->toBeFalsy()->and(Menu::find($b)->is_default)->toBeTruthy();

    $this->actingAs($u['owner'])->putJson("$url/$a", menuData(['title' => 'A', 'isDefault' => true]))->assertOk()->assertJsonPath('is_default', true);
    expect(Menu::find($a)->is_default)->toBeTruthy()->and(Menu::find($b)->is_default)->toBeFalsy();

    // An edit that does not mention it leaves it alone.
    $this->actingAs($u['owner'])->putJson("$url/$a", ['title' => 'A2'])->assertOk()->assertJsonPath('is_default', true);
});

it('answers validation problems with 422 and field errors, not 500', function () {
    [, $u, $url] = menuEvent();
    $id = $this->actingAs($u['owner'])->postJson($url, menuData())->json('id');

    $this->actingAs($u['owner'])->putJson("$url/$id", menuData(['title' => str_repeat('a', 300)]))->assertStatus(422)->assertJsonValidationErrors('title');
    $this->actingAs($u['owner'])->postJson($url, menuData(['allowCustomRequests' => 'quizás']))->assertStatus(422)->assertJsonValidationErrors('allowCustomRequests');
});

it('adds and removes menu items, and lists the guests with their choices', function () {
    [$event, $u, $url] = menuEvent();
    $id = $this->actingAs($u['owner'])->postJson($url, menuData())->json('id');
    Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null]);

    $item = $this->actingAs($u['owner'])->postJson("$url/$id/menu-item", ['name' => 'Pollo', 'description' => 'Al horno', 'itemType' => 'main'])->assertCreated()->json('id');
    expect($this->actingAs($u['owner'])->getJson($url)->json('0.menu_items'))->toHaveCount(1);
    $this->actingAs($u['owner'])->deleteJson("$url/$id/menu-item/$item")->assertOk();
    $this->actingAs($u['owner'])->getJson("$url/guests")->assertOk()->assertJsonCount(1, 'data');
});

it('shows menu activity on the dashboard feed instead of failing', function () {
    [$event, $u, $url] = menuEvent();
    $id = $this->actingAs($u['owner'])->postJson($url, menuData(['title' => 'Cena de gala']))->json('id');
    $this->actingAs($u['owner'])->putJson("$url/$id", menuData(['title' => 'Cena de gala 2']));
    $this->actingAs($u['owner'])->deleteJson("$url/$id");

    $response = $this->actingAs($u['owner'])->getJson("/api/v1/app/event/{$event->id}/dashboard-logs")->assertOk();

    expect($response->getContent())->toContain('Cena de gala');
});

it('keeps viewers read-only and strangers and other events out', function () {
    [, $u, $url] = menuEvent();
    [, $u2, $url2] = menuEvent();
    $id = $this->actingAs($u['owner'])->postJson($url, menuData())->json('id');
    $foreign = $this->actingAs($u2['owner'])->postJson($url2, menuData(['title' => 'Ajeno']))->json('id');

    $this->actingAs($u['viewer'])->getJson($url)->assertOk();
    $this->actingAs($u['viewer'])->postJson($url, menuData())->assertForbidden();
    $this->actingAs($u['viewer'])->deleteJson("$url/$id")->assertForbidden();
    $this->actingAs($u['stranger'])->getJson($url)->assertNotFound();
    $this->actingAs($u['owner'])->putJson("$url/$foreign", menuData())->assertNotFound();
    $this->actingAs($u['owner'])->deleteJson("$url/$foreign")->assertNotFound();
    expect(Menu::find($foreign))->not->toBeNull();
});
