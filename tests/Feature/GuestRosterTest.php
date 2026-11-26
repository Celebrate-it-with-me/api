<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function rosterEvent(string $plan = 'basic', string $status = 'draft'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => $status,
        'custom_url_slug' => 'xv-roster-' . uniqid(),
    ]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/roster"];
}

/** A family: one main guest and its companions with the given statuses. */
function family(Events $event, string $name, string $main, array $companions = [], array $extra = []): Guest
{
    $guest = Guest::factory()->create(array_merge(['event_id' => $event->id, 'parent_id' => null, 'name' => $name, 'rsvp_status' => $main], $extra));
    foreach ($companions as $i => $status) {
        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $guest->id, 'name' => "$name acompañante $i", 'rsvp_status' => $status]);
    }

    return $guest;
}

it('counts people by status for the whole event, not for the page', function () {
    [$event, $u, $url] = rosterEvent();
    family($event, 'Ana', 'attending', ['attending', 'pending']);
    family($event, 'Beto', 'pending');
    family($event, 'Carla', 'not-attending', ['not-attending']);

    $response = $this->actingAs($u['owner'])->getJson("$url?perPage=1")->assertOk();

    expect(Arr::except($response->json('counts'), 'delivery'))->toBe(['all' => 6, 'attending' => 2, 'pending' => 2, 'notAttending' => 2, 'families' => 3, 'limit' => 30])
        ->and($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.total'))->toBe(3);
});

it('filters families by the status of the main guest or any companion, except pending that is the main guest only', function () {
    [$event, $u, $url] = rosterEvent();
    family($event, 'Ana', 'attending', ['pending']);
    family($event, 'Beto', 'attending');
    family($event, 'Carla', 'not-attending');

    $names = fn (string $status) => collect($this->actingAs($u['owner'])->getJson("$url?status=$status")->json('data'))->pluck('name')->all();

    // P5: "pending" is the main guest who has not answered, like the send; Ana's pending companion shows on her row.
    expect($names('pending'))->toBe([])
        ->and($names('attending'))->toBe(['Ana', 'Beto'])
        ->and($names('not-attending'))->toBe(['Carla'])
        ->and(count($this->actingAs($u['owner'])->getJson("$url?status=nonsense")->json('data')))->toBe(3);
});

it('searches name, email, phone and companion names without being case sensitive', function () {
    [$event, $u, $url] = rosterEvent();
    family($event, 'Ana Pérez', 'pending', [], ['email' => 'ana@quinvite-test.org', 'phone' => '+13055550101']);
    family($event, 'Beto', 'pending', ['pending'], []);
    $search = fn (string $term) => collect($this->actingAs($u['owner'])->getJson("$url?search=" . urlencode($term))->json('data'))->pluck('name')->all();

    expect($search('PÉREZ'))->toBe(['Ana Pérez'])
        ->and($search('quinvite-test'))->toBe(['Ana Pérez'])
        ->and($search('0101'))->toBe(['Ana Pérez'])
        ->and($search('acompañante'))->toBe(['Beto'])
        ->and($search('100%'))->toBe([]);
});

it('gives each row its companions and the personal link', function () {
    [$event, $u, $url] = rosterEvent();
    $ana = family($event, 'Ana', 'pending', ['attending']);

    $row = $this->actingAs($u['owner'])->getJson($url)->json('data.0');

    expect($row['code'])->toBe($ana->code)
        ->and($row['link'])->toEndWith("/i/{$event->custom_url_slug}/{$ana->code}")
        ->and($row['companions'][0])->toMatchArray(['name' => 'Ana acompañante 0', 'status' => 'attending']);
});

it('edits a guest and a companion, validating the fields', function () {
    [$event, $u, $url] = rosterEvent();
    $ana = family($event, 'Ana', 'pending', ['pending']);
    $companion = $ana->companions()->first();
    $as = fn () => $this->actingAs($u['owner']);

    $as()->putJson("$url/{$ana->id}", ['name' => ' Ana María ', 'email' => 'am@example.com', 'phone' => '+1 305 555 0101', 'notes' => 'Vegetariana'])
        ->assertOk()->assertJsonPath('data.name', 'Ana María')->assertJsonPath('data.phone', '+1 305 555 0101');
    $as()->putJson("$url/{$companion->id}", ['name' => 'Luis'])->assertOk()->assertJsonPath('data.companions.0.name', 'Luis');

    $as()->putJson("$url/{$ana->id}", ['name' => ''])->assertJsonValidationErrors('name');
    $as()->putJson("$url/{$ana->id}", ['name' => 'Ana', 'email' => 'no'])->assertJsonValidationErrors('email');
    $as()->putJson("$url/{$ana->id}", ['name' => 'Ana', 'phone' => 'abc'])->assertJsonValidationErrors('phone');
    expect($ana->fresh()->name)->toBe('Ana María');
});

it('adds a companion to a main guest only, and respects the limit once published', function () {
    [$event, $u, $url] = rosterEvent('basic', 'published');
    $ana = family($event, 'Ana', 'pending', ['pending']);
    $companion = $ana->companions()->first();

    $this->actingAs($u['owner'])->postJson("$url/{$ana->id}/companions", ['name' => 'Pedro'])->assertCreated()->assertJsonCount(2, 'data.companions');
    $this->actingAs($u['owner'])->postJson("$url/{$companion->id}/companions", ['name' => 'X'])->assertStatus(422);

    Guest::factory()->count(30 - $event->guests()->count())->create(['event_id' => $event->id, 'parent_id' => null]);
    $this->actingAs($u['owner'])->postJson("$url/{$ana->id}/companions", ['name' => 'Extra'])
        ->assertStatus(402)->assertJsonPath('code', 'guest_limit_exceeded');
});

it('removes a companion or a whole family', function () {
    [$event, $u, $url] = rosterEvent();
    $ana = family($event, 'Ana', 'pending', ['pending', 'pending']);
    $companion = $ana->companions()->first();

    $this->actingAs($u['owner'])->deleteJson("$url/{$companion->id}")->assertOk()->assertJsonCount(1, 'data.companions');
    $this->actingAs($u['owner'])->deleteJson("$url/{$ana->id}")->assertOk();

    expect(Guest::where('event_id', $event->id)->count())->toBe(0);
});

it('keeps viewers read-only and strangers and other events out', function () {
    [$event, $u, $url] = rosterEvent();
    [$other] = rosterEvent();
    $mine = family($event, 'Ana', 'pending');
    $foreign = family($other, 'Ajena', 'pending');

    $this->actingAs($u['viewer'])->getJson($url)->assertOk();
    $this->actingAs($u['viewer'])->putJson("$url/{$mine->id}", ['name' => 'X'])->assertForbidden();
    $this->actingAs($u['viewer'])->deleteJson("$url/{$mine->id}")->assertForbidden();
    $this->actingAs($u['stranger'])->getJson($url)->assertNotFound();
    $this->actingAs($u['owner'])->putJson("$url/{$foreign->id}", ['name' => 'X'])->assertNotFound();
    $this->actingAs($u['owner'])->deleteJson("$url/{$foreign->id}")->assertNotFound();
    $this->actingAs($u['owner'])->postJson("$url/{$foreign->id}/companions", ['name' => 'X'])->assertNotFound();
    expect($foreign->fresh()->name)->toBe('Ajena');
});

it('does not let the organizer RSVP routes touch a guest of another event', function () {
    [$event, $u] = rosterEvent();
    [$other] = rosterEvent();
    $foreign = family($other, 'Ajena', 'pending');
    $base = "/api/v1/app/event/{$event->id}";

    $this->actingAs($u['owner'])->putJson("$base/organizer/rsvp/{$foreign->id}/confirm", ['guest' => ['rsvp_status' => 'attending']])->assertNotFound();
    $this->actingAs($u['owner'])->getJson("$base/organizer/rsvp/{$foreign->id}/status")->assertNotFound();
    $this->actingAs($u['owner'])->postJson("$base/rsvp/guests/{$foreign->id}/revert-confirmation")->assertNotFound();
    expect($foreign->fresh()->rsvp_status)->toBe('pending');
});

it('refreshes the counters after reverting a confirmation', function () {
    [$event, $u, $url] = rosterEvent();
    $ana = family($event, 'Ana', 'attending', ['attending']);
    $base = "/api/v1/app/event/{$event->id}";

    $this->actingAs($u['owner'])->getJson("$base/rsvp/stats")->assertOk();
    $this->actingAs($u['owner'])->postJson("$base/rsvp/guests/{$ana->id}/revert-confirmation")->assertOk();

    expect($this->actingAs($u['owner'])->getJson("$base/rsvp/stats")->json('data.pending.total'))->toBe(2);
});

it('lists the five latest answers of the main guests, newest first, without companions or pending', function () {
    [$event, $u] = rosterEvent();
    $base = "/api/v1/app/event/{$event->id}";
    foreach (range(1, 6) as $i) {
        family($event, "Familia $i", $i % 2 ? 'attending' : 'not-attending', ['attending'], ['rsvp_status_date' => now()->subDays(10 - $i)]);
    }
    family($event, 'Pendiente', 'pending', [], ['rsvp_status_date' => now()]);
    family($event, 'Sin fecha', 'attending');
    Guest::where('name', 'Sin fecha')->update(['rsvp_status_date' => null]);

    $data = $this->actingAs($u['owner'])->getJson("$base/rsvp/stats")->assertOk()->json('data');

    expect(array_column($data['recent'], 'name'))->toBe(['Familia 6', 'Familia 5', 'Familia 4', 'Familia 3', 'Familia 2'])
        ->and(array_keys($data['recent'][0]))->toBe(['name', 'status', 'statusDate'])
        ->and($data['recent'][0]['status'])->toBe('not-attending')
        ->and($data)->toHaveKeys(['attending', 'pending', 'not_attending', 'totals', 'response_rate']);
});

it('returns an empty recent list when nobody has answered', function () {
    [$event, $u] = rosterEvent();
    family($event, 'Ana', 'pending');

    expect($this->actingAs($u['owner'])->getJson("/api/v1/app/event/{$event->id}/rsvp/stats")->json('data.recent'))->toBe([]);
});

it('refreshes the recent answers when a family answers, is edited or is imported', function () {
    [$event, $u] = rosterEvent();
    $ana = family($event, 'Ana', 'pending', ['pending']);
    $base = "/api/v1/app/event/{$event->id}";
    $recent = fn () => array_column($this->actingAs($u['owner'])->getJson("$base/rsvp/stats")->json('data.recent'), 'name');

    expect($recent())->toBe([]);
    $this->actingAs($u['owner'])->putJson("$base/organizer/rsvp/{$ana->id}/confirm", ['guest' => ['rsvp_status' => 'attending']])->assertOk();
    expect($recent())->toBe(['Ana']);
    $this->actingAs($u['owner'])->postJson("$base/rsvp/guests/{$ana->id}/revert-confirmation")->assertOk();
    expect($recent())->toBe([]);

    // Edited: the new name shows at once, not after the 10-minute cache.
    $this->actingAs($u['owner'])->putJson("$base/organizer/rsvp/{$ana->id}/confirm", ['guest' => ['rsvp_status' => 'attending']])->assertOk();
    expect($recent())->toBe(['Ana']);
    $this->actingAs($u['owner'])->putJson("$base/roster/{$ana->id}", ['name' => 'Ana B.'])->assertOk();
    expect($recent())->toBe(['Ana B.']);

    // Imported: the counts move at once too (imported families are pending, so `recent` keeps its rows).
    $total = fn () => $this->actingAs($u['owner'])->getJson("$base/rsvp/stats")->json('data.totals.guests');
    $before = $total();
    $this->actingAs($u['owner'])->postJson("$base/roster/import", ['rows' => [['name' => 'Beto'], ['name' => 'Carla']]])->assertCreated();
    expect($total())->toBe($before + 2)->and($recent())->toBe(['Ana B.']);
});

it('leaves out the recent answers for a role that cannot see the guest list', function () {
    [$event, $u] = rosterEvent();
    family($event, 'Ana', 'attending');
    $url = "/api/v1/app/event/{$event->id}/rsvp/stats";

    // Today every event role has view_guests; take it away from the viewer to stand for a future role without it.
    $viewer = Role::findByName('viewer', 'web');
    $viewer->revokePermissionTo('view_guests');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The viewer reads first, so the shared cache is filled by a filtered request: the owner must still get names.
    $forViewer = $this->actingAs($u['viewer'])->getJson($url)->assertOk()->json('data');
    $forOwner = $this->actingAs($u['owner'])->getJson($url)->assertOk()->json('data');

    expect($forViewer['recent'])->toBe([])
        ->and($forViewer['attending'])->toBe($forOwner['attending'])
        ->and($forViewer['response_rate'])->toBe($forOwner['response_rate'])
        ->and(array_column($forOwner['recent'], 'name'))->toBe(['Ana']);
});

it('accepts the hyphenated status when confirming companions in bulk', function () {
    [$event, $u] = rosterEvent();
    $ana = family($event, 'Ana', 'pending', ['pending']);

    $this->actingAs($u['owner'])->putJson("/api/v1/app/event/{$event->id}/organizer/rsvp/{$ana->id}/bulk-companions", ['rsvp_status' => 'not-attending'])->assertOk();

    expect($ana->companions()->first()->rsvp_status)->toBe('not-attending');
});

describe('the language of each invitation', function () {
    it('shows hers and the effective one (hers, or the event\'s)', function () {
        [$event, $u, $url] = rosterEvent();
        $event->update(['locale' => 'en']);
        family($event, 'Ana', 'pending', [], ['locale' => 'es']);
        family($event, 'Beto', 'pending');

        $response = $this->actingAs($u['owner'])->getJson($url)->assertOk();

        expect($response->json('eventLocale'))->toBe('en')
            ->and(collect($response->json('data'))->map(fn ($r) => [$r['name'], $r['locale'], $r['effectiveLocale']])->all())
            ->toBe([['Ana', 'es', 'es'], ['Beto', null, 'en']]);
    });

    it('is set to es, en, or back to the event\'s with null', function () {
        [$event, $u, $url] = rosterEvent();
        $ana = family($event, 'Ana', 'pending');

        $this->actingAs($u['owner'])->patchJson("$url/{$ana->id}/locale", ['locale' => 'en'])
            ->assertOk()->assertJsonPath('data.locale', 'en')->assertJsonPath('data.effectiveLocale', 'en');
        expect($ana->fresh()->locale)->toBe('en');

        $this->actingAs($u['owner'])->patchJson("$url/{$ana->id}/locale", ['locale' => null])
            ->assertOk()->assertJsonPath('data.locale', null)->assertJsonPath('data.effectiveLocale', 'es');
        expect($ana->fresh()->locale)->toBeNull();
    });

    it('validates the value and requires it to be sent', function () {
        [$event, $u, $url] = rosterEvent();
        $ana = family($event, 'Ana', 'pending');

        foreach ([['locale' => 'fr'], ['locale' => ['es']], []] as $body) {
            $this->actingAs($u['owner'])->patchJson("$url/{$ana->id}/locale", $body)->assertUnprocessable();
        }
        expect($ana->fresh()->locale)->toBeNull();
    });

    it('needs edit_event: 401 anonymous, 404 outside, 403 for a viewer', function () {
        [$event, $u, $url] = rosterEvent();
        $ana = family($event, 'Ana', 'pending');

        $this->patchJson("$url/{$ana->id}/locale", ['locale' => 'en'])->assertUnauthorized();
        $this->actingAs($u['stranger'])->patchJson("$url/{$ana->id}/locale", ['locale' => 'en'])->assertNotFound();
        $this->actingAs($u['viewer'])->patchJson("$url/{$ana->id}/locale", ['locale' => 'en'])->assertForbidden();
        expect($ana->fresh()->locale)->toBeNull();
    });

    it('cannot reach a guest of another event, and belongs to the main guest only', function () {
        [$event, $u, $url] = rosterEvent();
        [$other] = rosterEvent();
        $foreign = family($other, 'Ajena', 'pending');
        $ana = family($event, 'Ana', 'pending', ['pending']);
        $companion = Guest::query()->where('parent_id', $ana->id)->first();

        $this->actingAs($u['owner'])->patchJson("$url/{$foreign->id}/locale", ['locale' => 'en'])->assertNotFound();
        $this->actingAs($u['owner'])->patchJson("$url/{$companion->id}/locale", ['locale' => 'en'])->assertUnprocessable();
        expect($foreign->fresh()->locale)->toBeNull();
    });
});
