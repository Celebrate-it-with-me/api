<?php

use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Padrino;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function padrinoEvent(string $plan = 'basic', array $extra = []): array
{
    $event = Events::factory()->create(array_merge([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => 'draft',
        'custom_url_slug' => 'xv-pad-' . uniqid(),
    ], $extra));
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}"];
}

function padrinoData(array $extra = []): array
{
    return array_merge(['name' => 'Tío Raúl', 'phone' => '+1 305 555 0101', 'relationship' => 'Tío', 'status' => 'invited', 'publicThanks' => false], $extra);
}

function budgetItem($test, $owner, string $api, string $title = 'Pastel', int $cost = 400): int
{
    $test->actingAs($owner)->getJson("$api/budget");

    return $test->actingAs($owner)->postJson("$api/budget/items", ['title' => $title, 'estimatedCost' => $cost])->assertCreated()->json('data.id');
}

describe('padrinos', function () {
    it('creates, edits and lists padrinos', function () {
        [, $u, $api] = padrinoEvent();

        $id = $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData())->assertCreated()->json('data.id');
        $this->actingAs($u['owner'])->putJson("$api/padrinos/$id", padrinoData(['name' => ' Tío Raúl Pérez ', 'status' => 'accepted']))
            ->assertOk()->assertJsonPath('data.name', 'Tío Raúl Pérez')->assertJsonPath('data.status', 'accepted');

        $list = $this->actingAs($u['owner'])->getJson("$api/padrinos")->assertOk();
        expect($list->json('data'))->toHaveCount(1)->and($list->json('totals'))->toMatchArray(['committed' => 0, 'paid' => 0, 'count' => 1])
            ->and($list->json('data.0.progress'))->toBe('none');
    });

    it('validates the fields', function () {
        [$event, $u, $api] = padrinoEvent();
        $other = Guest::factory()->create(['parent_id' => null]); // a guest of another event
        $as = fn (array $p) => $this->actingAs($u['owner'])->postJson("$api/padrinos", $p);

        $as(padrinoData(['name' => '']))->assertJsonValidationErrors('name');
        $as(padrinoData(['status' => 'paid']))->assertJsonValidationErrors('status');
        $as(padrinoData(['phone' => 'abc']))->assertJsonValidationErrors('phone');
        $as(padrinoData(['guestId' => $other->id]))->assertJsonValidationErrors('guestId');
        $as(['name' => 'X'])->assertJsonValidationErrors(['status', 'publicThanks']);
        $mine = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null]);
        $as(padrinoData(['guestId' => $mine->id]))->assertCreated()->assertJsonPath('data.guestId', $mine->id);
    });

    it('computes what a padrino promised and paid from the budget, never from a stored total', function () {
        [, $u, $api] = padrinoEvent();
        $cake = budgetItem($this, $u['owner'], $api, 'Pastel', 400);
        $dj = budgetItem($this, $u['owner'], $api, 'DJ', 1200);
        $id = $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData())->json('data.id');

        $row = $this->actingAs($u['owner'])->putJson("$api/padrinos/$id/commitments", ['commitments' => [['budgetItemId' => $cake, 'amount' => 400], ['budgetItemId' => $dj, 'amount' => 600]]])
            ->assertOk()->json('data');
        expect($row)->toMatchArray(['committed' => 1000, 'paid' => 0, 'remaining' => 1000, 'progress' => 'unpaid'])
            ->and(collect($row['commitments'])->pluck('itemTitle')->sort()->values()->all())->toBe(['DJ', 'Pastel']);

        // Payments marked as the padrino's are what he has paid; the family's do not count for him.
        $this->actingAs($u['owner'])->postJson("$api/budget/items/$cake/payments", ['amount' => 250, 'paidOn' => '2027-01-01', 'padrinoId' => $id])->assertCreated()
            ->assertJsonPath('data.payments.0.padrinoName', 'Tío Raúl');
        $this->actingAs($u['owner'])->postJson("$api/budget/items/$dj/payments", ['amount' => 100, 'paidOn' => '2027-01-02'])->assertCreated();

        $list = $this->actingAs($u['owner'])->getJson("$api/padrinos")->json();
        expect($list['data'][0])->toMatchArray(['paid' => 250, 'remaining' => 750, 'progress' => 'partial'])
            ->and($list['totals'])->toMatchArray(['committed' => 1000, 'paid' => 250, 'remaining' => 750]);

        $this->actingAs($u['owner'])->postJson("$api/budget/items/$cake/payments", ['amount' => 750, 'paidOn' => '2027-01-03', 'padrinoId' => $id])->assertCreated();
        expect($this->actingAs($u['owner'])->getJson("$api/padrinos")->json('data.0.progress'))->toBe('paid');
    });

    it('replaces the commitments as a set and validates them', function () {
        [, $u, $api] = padrinoEvent();
        $cake = budgetItem($this, $u['owner'], $api);
        $id = $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData())->json('data.id');
        $put = fn (array $c) => $this->actingAs($u['owner'])->putJson("$api/padrinos/$id/commitments", ['commitments' => $c]);

        $put([['budgetItemId' => $cake, 'amount' => 100]])->assertOk();
        $put([['budgetItemId' => $cake, 'amount' => 150]])->assertOk()->assertJsonPath('data.committed', 150)->assertJsonCount(1, 'data.commitments');
        $put([])->assertOk()->assertJsonPath('data.committed', 0);
        $put([['budgetItemId' => $cake, 'amount' => 0]])->assertJsonValidationErrors('commitments.0.amount');
        $put([['budgetItemId' => 999999, 'amount' => 5]])->assertJsonValidationErrors('commitments.0.budgetItemId');
        $put([['budgetItemId' => $cake, 'amount' => 5], ['budgetItemId' => $cake, 'amount' => 6]])->assertJsonValidationErrors('commitments.0.budgetItemId');
        $this->actingAs($u['owner'])->putJson("$api/padrinos/$id/commitments", [])->assertJsonValidationErrors('commitments');
    });

    it('cannot commit to an item of another event, nor mark a payment as paid by a padrino of another event', function () {
        [, $u, $api] = padrinoEvent();
        [, $u2, $api2] = padrinoEvent();
        $mine = $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData())->json('data.id');
        $foreignItem = budgetItem($this, $u2['owner'], $api2);
        $foreignPadrino = $this->actingAs($u2['owner'])->postJson("$api2/padrinos", padrinoData(['name' => 'Ajeno']))->json('data.id');
        $item = budgetItem($this, $u['owner'], $api);

        $this->actingAs($u['owner'])->putJson("$api/padrinos/$mine/commitments", ['commitments' => [['budgetItemId' => $foreignItem, 'amount' => 5]]])->assertJsonValidationErrors('commitments.0.budgetItemId');
        $this->actingAs($u['owner'])->postJson("$api/budget/items/$item/payments", ['amount' => 5, 'paidOn' => '2027-01-01', 'padrinoId' => $foreignPadrino])->assertJsonValidationErrors('padrinoId');
    });

    it('refuses to remove a padrino who has payments, and removes one who has none', function () {
        [, $u, $api] = padrinoEvent();
        $item = budgetItem($this, $u['owner'], $api);
        $paid = $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData())->json('data.id');
        $free = $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData(['name' => 'Sin pagos']))->json('data.id');
        $this->actingAs($u['owner'])->postJson("$api/budget/items/$item/payments", ['amount' => 5, 'paidOn' => '2027-01-01', 'padrinoId' => $paid])->assertCreated();

        $this->actingAs($u['owner'])->deleteJson("$api/padrinos/$paid")->assertStatus(422);
        $this->actingAs($u['owner'])->deleteJson("$api/padrinos/$free")->assertOk();
        expect(Padrino::count())->toBe(1);
    });

    it('keeps viewers read-only and strangers and other events out', function () {
        [, $u, $api] = padrinoEvent();
        [, $u2, $api2] = padrinoEvent();
        $id = $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData())->json('data.id');
        $foreign = $this->actingAs($u2['owner'])->postJson("$api2/padrinos", padrinoData(['name' => 'Ajeno']))->json('data.id');

        $this->actingAs($u['viewer'])->getJson("$api/padrinos")->assertOk();
        $this->actingAs($u['viewer'])->postJson("$api/padrinos", padrinoData())->assertForbidden();
        $this->actingAs($u['viewer'])->deleteJson("$api/padrinos/$id")->assertForbidden();
        $this->actingAs($u['stranger'])->getJson("$api/padrinos")->assertNotFound();
        $this->actingAs($u['owner'])->putJson("$api/padrinos/$foreign", padrinoData())->assertNotFound();
        $this->actingAs($u['owner'])->deleteJson("$api/padrinos/$foreign")->assertNotFound();
        $this->actingAs($u['owner'])->putJson("$api/padrinos/$foreign/commitments", ['commitments' => []])->assertNotFound();
        expect(Padrino::find($foreign))->not->toBeNull();
    });
});

describe('public invitation', function () {
    function padrinoLink(string $plan): array
    {
        [$event, $u, $api] = padrinoEvent($plan, ['status' => 'published']);
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'AB1234', 'parent_id' => null]);
        test()->seed(InvitationTemplateSeeder::class);

        return [$event, $u, $api, "/api/v1/app/invitation/{$event->custom_url_slug}/AB1234"];
    }

    it('shows only the padrinos marked public, with what they sponsor and never amounts, on a premium plan', function () {
        [, $u, $api, $url] = padrinoLink('premium');
        $cake = budgetItem($this, $u['owner'], $api, 'Pastel', 400);
        $shown = $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData(['publicThanks' => true, 'thanksText' => 'Gracias por tanto cariño']))->json('data.id');
        $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData(['name' => 'Oculto', 'publicThanks' => false]));
        $this->actingAs($u['owner'])->putJson("$api/padrinos/$shown/commitments", ['commitments' => [['budgetItemId' => $cake, 'amount' => 400]]]);

        $response = $this->getJson($url)->assertOk();
        $section = collect($response->json('data.sections'))->firstWhere('type', 'padrinos');

        expect($section['title'])->toBe('Con el cariño de')
            ->and($section['data']['items'])->toHaveCount(1)
            ->and($section['data']['items'][0])->toMatchArray(['name' => 'Tío Raúl', 'sponsors' => ['Pastel'], 'thanks' => 'Gracias por tanto cariño']);
        expect($response->getContent())->not->toContain('Oculto')->not->toContain('400')->not->toContain('+1 305');
    });

    it('does not show the section on the free plan, or when nobody is public', function () {
        [, $u, $api, $url] = padrinoLink('basic');
        $this->actingAs($u['owner'])->postJson("$api/padrinos", padrinoData(['publicThanks' => true]));
        expect(collect($this->getJson($url)->json('data.sections'))->pluck('type')->all())->not->toContain('padrinos');

        [, $u2, $api2, $url2] = padrinoLink('premium');
        $this->actingAs($u2['owner'])->postJson("$api2/padrinos", padrinoData(['publicThanks' => false]));
        expect(collect($this->getJson($url2)->json('data.sections'))->pluck('type')->all())->not->toContain('padrinos');
    });
});
