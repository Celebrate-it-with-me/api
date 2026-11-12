<?php

use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** A draft event with an owner, an editor and a viewer. */
function lifecycleEvent(array $attributes = []): array
{
    $event = Events::factory()->draft()->create($attributes + [
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
        'event_name' => 'Los XV de Valentina',
        'custom_url_slug' => 'los-xv-de-valentina',
    ]);
    EventFeature::create(['event_id' => $event->id, 'rsvp' => true, 'budget' => true, 'menu' => false]);

    $members = [];
    foreach (['owner', 'editor', 'viewer'] as $role) {
        $members[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $members[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $members['stranger'] = User::factory()->create();

    return [$event, $members];
}

function createPayload(array $overrides = []): array
{
    return $overrides + [
        'eventName' => 'Mis XV',
        'eventType' => EventType::where('slug', 'quinceanera')->value('id'),
        'startDate' => '12/01/2027 18:00',
    ];
}

describe('creating an event', function () {
    it('needs only a name, a type and a start date; it is born as a draft', function () {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/app/event', createPayload())->assertSuccessful();

        $event = Events::find($response->json('data.id'));
        expect($event->status)->toBe('draft')
            ->and($event->end_date)->toBeNull()
            ->and($event->event_description)->toBeNull()
            ->and($event->published_at)->toBeNull()
            ->and($response->json('data.slugLocked'))->toBeFalse()
            ->and($response->json('data'))->not->toHaveKey('visibility');
    });

    it('ignores a status sent by the client: publishing is its own action', function () {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->postJson('/api/v1/app/event', createPayload(['status' => 'published']))->assertSuccessful();

        expect(Events::find($response->json('data.id'))->status)->toBe('draft');
    });

    it('builds a readable, unique address from the name', function () {
        $owner = User::factory()->create();

        $first = $this->actingAs($owner)->postJson('/api/v1/app/event', createPayload(['eventName' => 'Los XV de Valentina']))->json('data.customUrlSlug');
        $second = $this->actingAs($owner)->postJson('/api/v1/app/event', createPayload(['eventName' => 'Los XV de Valentina']))->json('data.customUrlSlug');

        expect($first)->toBe('los-xv-de-valentina')->and($second)->toBe('los-xv-de-valentina-2');
    });

    it('rejects an address that is taken (even by a deleted event) or badly formed', function () {
        $owner = User::factory()->create();
        [$taken, $m] = lifecycleEvent();
        $taken->delete(); // soft deleted: the unique index still holds it

        $this->actingAs($owner)->postJson('/api/v1/app/event', createPayload(['customUrlSlug' => 'los-xv-de-valentina']))
            ->assertStatus(422)->assertJsonValidationErrors('customUrlSlug');
        $this->actingAs($owner)->postJson('/api/v1/app/event', createPayload(['customUrlSlug' => 'Mal Formada!']))
            ->assertStatus(422)->assertJsonValidationErrors('customUrlSlug');
    });

    it('rejects an end date before the start', function () {
        $owner = User::factory()->create();

        $this->actingAs($owner)->postJson('/api/v1/app/event', createPayload(['endDate' => '11/30/2027 10:00']))
            ->assertStatus(422)->assertJsonValidationErrors('endDate');
    });
});

describe('editing the details', function () {
    it('changes only what is sent and never switches features off', function () {
        [$event, $m] = lifecycleEvent();

        $this->actingAs($m['editor'])->patchJson("/api/v1/app/event/{$event->id}", ['eventName' => 'Los XV de Vale'])->assertOk();

        $event->refresh();
        expect($event->event_name)->toBe('Los XV de Vale')
            ->and($event->custom_url_slug)->toBe('los-xv-de-valentina')
            ->and($event->eventFeature->rsvp)->toBeTrue()
            ->and($event->eventFeature->budget)->toBeTrue();
    });

    it('can clear the description and the end date', function () {
        [$event, $m] = lifecycleEvent(['event_description' => 'algo', 'end_date' => '2027-12-01 23:00:00']);

        $this->actingAs($m['owner'])->patchJson("/api/v1/app/event/{$event->id}", ['eventDescription' => null, 'endDate' => null])->assertOk();

        expect($event->fresh())->event_description->toBeNull()->end_date->toBeNull();
    });

    it('still accepts PUT for older clients, and keeps the budget feature on', function () {
        [$event, $m] = lifecycleEvent();

        $this->actingAs($m['owner'])->putJson("/api/v1/app/event/{$event->id}", ['eventName' => 'Via PUT'])->assertOk();

        expect($event->fresh()->event_name)->toBe('Via PUT')->and($event->fresh()->eventFeature->budget)->toBeTrue();
    });

    it('cannot change the status or the plan', function () {
        [$event, $m] = lifecycleEvent();

        $this->actingAs($m['owner'])->patchJson("/api/v1/app/event/{$event->id}", [
            'status' => 'published', 'eventPlan' => EventPlan::orderByDesc('id')->value('id'),
        ])->assertOk();

        expect($event->fresh()->status)->toBe('draft')->and($event->fresh()->event_plan_id)->toBe(EventPlan::orderBy('id')->value('id'));
    });

    it('rejects an address owned by another event', function () {
        [$event, $m] = lifecycleEvent();
        Events::factory()->create(['custom_url_slug' => 'ocupada', 'event_type_id' => $event->event_type_id, 'event_plan_id' => $event->event_plan_id]);

        $this->actingAs($m['owner'])->patchJson("/api/v1/app/event/{$event->id}", ['customUrlSlug' => 'ocupada'])
            ->assertStatus(422)->assertJsonValidationErrors('customUrlSlug');
    });

    it('lets a draft change its address freely', function () {
        [$event, $m] = lifecycleEvent();

        $this->actingAs($m['owner'])->patchJson("/api/v1/app/event/{$event->id}", ['customUrlSlug' => 'valentina-xv'])->assertOk();
        expect($event->fresh()->custom_url_slug)->toBe('valentina-xv');
    });
});

describe('publishing', function () {
    it('publishes a draft, remembers when, and locks the address', function () {
        [$event, $m] = lifecycleEvent();

        $response = $this->actingAs($m['editor'])->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();

        expect($response->json('data.status'))->toBe('published')
            ->and($response->json('data.slugLocked'))->toBeTrue()
            ->and($response->json('data.publishedAt'))->not->toBeNull()
            ->and($event->fresh()->published_at)->not->toBeNull();
    });

    it('does not publish an event without a type', function () {
        [$event, $m] = lifecycleEvent(['event_type_id' => null]);

        $this->actingAs($m['owner'])->postJson("/api/v1/app/event/{$event->id}/publish")
            ->assertStatus(422)->assertJsonValidationErrors('eventType');
        expect($event->fresh()->status)->toBe('draft');
    });

    it('refuses to publish twice or to publish an archived event', function () {
        [$event, $m] = lifecycleEvent();
        $api = "/api/v1/app/event/{$event->id}";

        $this->actingAs($m['owner'])->postJson("$api/publish")->assertOk();
        $this->actingAs($m['owner'])->postJson("$api/publish")->assertStatus(422)->assertJsonValidationErrors('status');

        $this->actingAs($m['owner'])->postJson("$api/archive")->assertOk();
        $this->actingAs($m['owner'])->postJson("$api/publish")->assertStatus(422)->assertJsonValidationErrors('status');
    });

    it('keeps the first publication date when published again', function () {
        [$event, $m] = lifecycleEvent();
        $api = "/api/v1/app/event/{$event->id}";

        $this->actingAs($m['owner'])->postJson("$api/publish")->assertOk();
        $first = $event->fresh()->published_at;
        $this->travel(2)->days();
        $this->actingAs($m['owner'])->postJson("$api/unpublish")->assertOk();
        $this->actingAs($m['owner'])->postJson("$api/publish")->assertOk();

        expect($event->fresh()->published_at->equalTo($first))->toBeTrue();
    });
});

describe('the locked address', function () {
    it('cannot be changed once published, and the lock survives unpublishing', function () {
        [$event, $m] = lifecycleEvent();
        $api = "/api/v1/app/event/{$event->id}";
        $this->actingAs($m['owner'])->postJson("$api/publish")->assertOk();

        $this->actingAs($m['owner'])->patchJson($api, ['customUrlSlug' => 'otra-direccion'])
            ->assertStatus(422)->assertJsonValidationErrors('customUrlSlug');

        $this->actingAs($m['owner'])->postJson("$api/unpublish")->assertOk();
        $this->actingAs($m['owner'])->patchJson($api, ['customUrlSlug' => 'otra-direccion'])
            ->assertStatus(422)->assertJsonValidationErrors('customUrlSlug');
        expect($event->fresh()->custom_url_slug)->toBe('los-xv-de-valentina');
    });

    it('does not complain when the same address is sent back, and other fields stay editable', function () {
        [$event, $m] = lifecycleEvent();
        $api = "/api/v1/app/event/{$event->id}";
        $this->actingAs($m['owner'])->postJson("$api/publish")->assertOk();

        $this->actingAs($m['owner'])->patchJson($api, ['customUrlSlug' => 'los-xv-de-valentina', 'eventName' => 'Nuevo nombre'])->assertOk();
        expect($event->fresh()->event_name)->toBe('Nuevo nombre');
    });

    it('also holds for the old PUT', function () {
        [$event, $m] = lifecycleEvent();
        $api = "/api/v1/app/event/{$event->id}";
        $this->actingAs($m['owner'])->postJson("$api/publish")->assertOk();

        $this->actingAs($m['owner'])->putJson($api, ['customUrlSlug' => 'otra-direccion'])->assertStatus(422);
    });
});

describe('archiving and restoring', function () {
    it('archives a draft or a published event and restores it as a draft', function () {
        [$event, $m] = lifecycleEvent();
        $api = "/api/v1/app/event/{$event->id}";

        $this->actingAs($m['owner'])->postJson("$api/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->actingAs($m['owner'])->postJson("$api/archive")->assertStatus(422);
        $this->actingAs($m['owner'])->postJson("$api/restore")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->actingAs($m['owner'])->postJson("$api/restore")->assertStatus(422);

        $this->actingAs($m['owner'])->postJson("$api/publish")->assertOk();
        $this->actingAs($m['owner'])->postJson("$api/archive")->assertOk()->assertJsonPath('data.status', 'archived');
    });

    it('takes the public invitation offline', function () {
        [$event, $m] = lifecycleEvent();
        $guest = Guest::factory()->create(['event_id' => $event->id, 'code' => 'VAL001', 'parent_id' => null]);
        $api = "/api/v1/app/event/{$event->id}";
        $invitation = "/api/v1/app/invitation/{$event->custom_url_slug}/VAL001";

        $this->getJson($invitation)->assertNotFound(); // draft
        $this->actingAs($m['owner'])->postJson("$api/publish")->assertOk();
        $this->getJson($invitation)->assertOk();
        $this->actingAs($m['owner'])->postJson("$api/archive")->assertOk();
        $this->getJson($invitation)->assertNotFound();
    });
});

describe('who may do what', function () {
    it('keeps strangers out and viewers read-only', function () {
        [$event, $m] = lifecycleEvent();
        $api = "/api/v1/app/event/{$event->id}";

        foreach (['publish', 'unpublish', 'archive', 'restore'] as $action) {
            $this->actingAs($m['stranger'])->postJson("$api/$action")->assertNotFound();
            $this->actingAs($m['viewer'])->postJson("$api/$action")->assertForbidden();
        }
        $this->actingAs($m['viewer'])->patchJson($api, ['eventName' => 'x'])->assertForbidden();
        $this->actingAs($m['stranger'])->patchJson($api, ['eventName' => 'x'])->assertNotFound();
        expect($event->fresh()->status)->toBe('draft')->and($event->fresh()->event_name)->toBe('Los XV de Valentina');
    });
});
