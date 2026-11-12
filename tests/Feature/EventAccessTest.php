<?php

use App\Models\BudgetItem;
use App\Models\EventBudget;
use App\Models\EventFeature;
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
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** An event with one user per role plus a stranger. */
function eventWithMembers(): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
        'status' => 'draft',
        'event_name' => 'Los XV de Valentina',
    ]);
    EventFeature::create(['event_id' => $event->id]);

    $members = [];
    foreach (['owner', 'editor', 'viewer'] as $role) {
        $members[$role] = User::factory()->create();
        EventUserRole::create([
            'event_id' => $event->id,
            'user_id' => $members[$role]->id,
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
    }
    $members['stranger'] = User::factory()->create();

    return [$event, $members];
}

function updatePayload(Events $event, string $name = 'Nuevo nombre'): array
{
    return [
        'eventName' => $name, 'eventDescription' => 'x', 'eventType' => $event->event_type_id,
        'startDate' => '12/01/2027 18:00', 'endDate' => '12/01/2027 23:00',
        'status' => 'draft', 'visibility' => 'private', 'customUrlSlug' => $event->custom_url_slug,
    ];
}

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

describe('a stranger', function () {
    it('gets a 404 on every event route and changes nothing', function () {
        [$event, $m] = eventWithMembers();
        $api = "/api/v1/app/event/{$event->id}";
        $as = fn () => $this->actingAs($m['stranger']);

        $as()->getJson($api)->assertNotFound();
        $as()->getJson("$api/roster")->assertNotFound();
        $as()->getJson("$api/rsvp/stats")->assertNotFound();
        $as()->getJson("$api/dashboard-logs")->assertNotFound();
        $as()->getJson("$api/permissions")->assertNotFound();
        $as()->putJson($api, updatePayload($event, 'HACKED'))->assertNotFound();
        $as()->deleteJson($api)->assertNotFound();
        $as()->postJson("$api/guests", [])->assertNotFound();

        expect($event->fresh()->event_name)->toBe('Los XV de Valentina')
            ->and(Events::find($event->id))->not->toBeNull();
    });

    it('cannot make the event active, and does not see it in their list', function () {
        [$event, $m] = eventWithMembers();

        $this->actingAs($m['stranger'])->patchJson('/api/v1/app/event/active-event', ['eventId' => $event->id])->assertNotFound();
        expect($m['stranger']->fresh()->last_active_event_id)->toBeNull();

        $ids = collect($this->actingAs($m['stranger'])->getJson('/api/v1/app/events')->json('data.events'))->pluck('id');
        expect($ids)->toBeEmpty();
    });

    it('does not get the event back as their last active one after losing access', function () {
        [$event, $m] = eventWithMembers();
        $m['stranger']->update(['last_active_event_id' => $event->id]);

        $this->actingAs($m['stranger'])->getJson('/api/v1/app/events')->assertOk()
            ->assertJsonPath('data.last_active_event', null);
    });
});

describe('a viewer', function () {
    it('can read but not change anything', function () {
        [$event, $m] = eventWithMembers();
        $api = "/api/v1/app/event/{$event->id}";
        $as = fn () => $this->actingAs($m['viewer']);

        $as()->getJson($api)->assertOk();
        $as()->getJson("$api/roster")->assertOk();
        $as()->putJson($api, updatePayload($event))->assertForbidden();
        $as()->postJson("$api/guests", [])->assertForbidden();
        $as()->deleteJson($api)->assertForbidden();

        expect($event->fresh()->event_name)->toBe('Los XV de Valentina');
    });
});

describe('an editor', function () {
    it('can edit but not delete or manage collaborators', function () {
        [$event, $m] = eventWithMembers();
        $api = "/api/v1/app/event/{$event->id}";
        $as = fn () => $this->actingAs($m['editor']);

        $as()->putJson($api, updatePayload($event, 'Editado'))->assertOk();
        expect($event->fresh()->event_name)->toBe('Editado');

        $as()->deleteJson($api)->assertForbidden();
        $as()->postJson("$api/collaborators", ['email' => 'x@y.test'])->assertForbidden();
        expect(Events::find($event->id))->not->toBeNull();
    });
});

describe('an owner', function () {
    it('can edit and delete', function () {
        [$event, $m] = eventWithMembers();
        $api = "/api/v1/app/event/{$event->id}";

        $this->actingAs($m['owner'])->putJson($api, updatePayload($event, 'Del dueño'))->assertOk();
        $this->actingAs($m['owner'])->deleteJson($api)->assertOk();
        expect(Events::find($event->id))->toBeNull();
    });

    it('can make the event active', function () {
        [$event, $m] = eventWithMembers();

        $this->actingAs($m['owner'])->patchJson('/api/v1/app/event/active-event', ['eventId' => $event->id])->assertOk();
        expect($m['owner']->fresh()->last_active_event_id)->toBe($event->id);
    });

    it('does not change the plan when editing', function () {
        [$event, $m] = eventWithMembers();
        $premium = EventPlan::orderByDesc('id')->value('id');

        $this->actingAs($m['owner'])->putJson("/api/v1/app/event/{$event->id}", updatePayload($event) + ['eventPlan' => $premium])->assertOk();
        expect($event->fresh()->event_plan_id)->toBe(EventPlan::orderBy('id')->value('id'));
    });
});

describe('event creation and search', function () {
    it('assigns the entry plan on the server, whatever the client sends', function () {
        $user = User::factory()->create();
        $top = EventPlan::orderByDesc('id')->value('id');

        $response = $this->actingAs($user)->postJson('/api/v1/app/event', [
            'eventName' => 'Mi quince', 'eventDescription' => 'x',
            'eventType' => EventType::where('slug', 'quinceanera')->value('id'), 'eventPlan' => $top,
            'startDate' => '12/01/2027 18:00', 'endDate' => '12/01/2027 23:00', 'status' => 'draft', 'visibility' => 'private',
        ])->assertSuccessful();

        $created = Events::find($response->json('data.id'));
        expect($created->event_plan_id)->toBe(EventPlan::orderBy('id')->value('id'))->not->toBe($top);
    });

    it('searches only among the events of the user', function () {
        [$event, $m] = eventWithMembers();

        $mine = $this->actingAs($m['owner'])->getJson('/api/v1/app/event/filters?query=Valentina')->assertOk();
        expect($mine->json('data'))->toHaveCount(1);

        $theirs = $this->actingAs($m['stranger'])->getJson('/api/v1/app/event/filters?query=Valentina')->assertOk();
        expect($theirs->json('data'))->toBeEmpty();
    });
});

describe('collaboration invites', function () {
    it('lets someone who is not a member reach the accept route (the controller checks the invite)', function () {
        [$event, $m] = eventWithMembers();

        $this->actingAs($m['stranger'])->postJson("/api/v1/app/event/{$event->id}/collaborators/invite/999999/accept")
            ->assertNotFound()
            ->assertJsonPath('message', 'Invalid or expired invitation.');
    });
});

describe('resources of another event', function () {
    it('are not reachable through the route of an event the user belongs to', function () {
        [$eventA, $a] = eventWithMembers();
        [$eventB] = eventWithMembers();
        $guestB = Guest::factory()->create(['event_id' => $eventB->id]);

        $this->actingAs($a['owner'])->putJson("/api/v1/app/event/{$eventA->id}/roster/{$guestB->id}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($a['owner'])->deleteJson("/api/v1/app/event/{$eventA->id}/roster/{$guestB->id}")->assertNotFound();
        expect(Guest::find($guestB->id))->not->toBeNull();
    });

    it('still work through their own event', function () {
        [$event, $m] = eventWithMembers();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->actingAs($m['owner'])->putJson("/api/v1/app/event/{$event->id}/roster/{$guest->id}", ['name' => 'Nuevo nombre'])->assertOk();
    });
});

describe('child resources without an event_id', function () {
    it('must belong to the parent in the URL', function () {
        [$eventA, $a] = eventWithMembers();
        [$eventB] = eventWithMembers();
        $budgetA = EventBudget::create(['event_id' => $eventA->id, 'budget_cap' => 1000]);
        $budgetB = EventBudget::create(['event_id' => $eventB->id, 'budget_cap' => 1000]);
        $itemB = BudgetItem::create([
            'event_budget_id' => $budgetB->id, 'title' => 'Salón', 'estimated_cost' => 100,
        ]);

        $this->actingAs($a['owner'])
            ->deleteJson("/api/v1/app/event/{$eventA->id}/budget/items/{$itemB->id}")
            ->assertNotFound();
        expect(BudgetItem::find($itemB->id))->not->toBeNull();
    });
});

describe('menu items', function () {
    it('must belong to the menu in the URL', function () {
        [$eventA, $a] = eventWithMembers();
        [$eventB] = eventWithMembers();
        $menuA = Menu::create(['event_id' => $eventA->id, 'title' => 'A']);
        $menuB = Menu::create(['event_id' => $eventB->id, 'title' => 'B']);
        $itemB = MenuItem::create(['menu_id' => $menuB->id, 'name' => 'Pollo', 'type' => 'main']);

        $this->actingAs($a['owner'])
            ->deleteJson("/api/v1/app/event/{$eventA->id}/menus/{$menuA->id}/menu-item/{$itemB->id}")
            ->assertNotFound();
        expect(MenuItem::find($itemB->id))->not->toBeNull();
    });
});
