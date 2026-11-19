<?php

use App\Models\BudgetPayment;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function paidSetup(): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
        'status' => 'draft',
        'custom_url_slug' => 'xv-paid-' . uniqid(),
    ]);
    $u = [];
    foreach (['owner', 'editor', 'viewer'] as $role) {
        $u[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $u[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $api = "/api/v1/app/event/{$event->id}";
    test()->actingAs($u['owner'])->getJson("$api/budget");

    return [$u, $api];
}

function paidItem($test, $owner, string $api, string $title, int $cost, array $payments = []): int
{
    $id = $test->actingAs($owner)->postJson("$api/budget/items", ['title' => $title, 'estimatedCost' => $cost])->assertCreated()->json('data.id');
    foreach ($payments as $i => $p) {
        $test->actingAs($owner)->postJson("$api/budget/items/$id/payments", ['amount' => $p[0], 'paidOn' => '2027-01-0' . ($i + 1)] + ($p[1] ?? []))->assertCreated();
    }

    return $id;
}

function paidPadrino($test, $owner, string $api, string $name = 'Tío Raúl'): int
{
    return $test->actingAs($owner)->postJson("$api/padrinos", ['name' => $name, 'status' => 'invited', 'publicThanks' => false])->assertCreated()->json('data.id');
}

function commit($test, $user, string $api, int $padrino, array $c)
{
    return $test->actingAs($user)->putJson("$api/padrinos/$padrino/commitments", ['commitments' => $c]);
}

describe('commitments on items that already have payments', function () {
    it('works as before when the item has no payments, and returns no warnings', function () {
        [$u, $api] = paidSetup();
        $item = paidItem($this, $u['owner'], $api, 'DJ', 1000);
        $p = paidPadrino($this, $u['owner'], $api);

        commit($this, $u['owner'], $api, $p, [['budgetItemId' => $item, 'amount' => 600]])
            ->assertOk()->assertJsonPath('data.committed', 600)->assertJsonPath('warnings', []);
    });

    it('refuses with 409 and the conflict detail when the family already paid, changing nothing', function () {
        [$u, $api] = paidSetup();
        $item = paidItem($this, $u['owner'], $api, 'Banquete', 1000, [[300, ['note' => 'anticipo']], [200]]);
        $p = paidPadrino($this, $u['owner'], $api);

        $r = commit($this, $u['owner'], $api, $p, [['budgetItemId' => $item, 'amount' => 500]])
            ->assertStatus(409)->assertJsonPath('code', 'commitment_item_paid')
            ->assertJsonPath('conflicts.0.budgetItemId', $item)->assertJsonPath('conflicts.0.itemName', 'Banquete')
            ->assertJsonPath('conflicts.0.cost', 1000)->assertJsonPath('conflicts.0.paid', 500)->assertJsonPath('conflicts.0.remaining', 500)
            ->assertJsonCount(2, 'conflicts.0.unattributedPayments');
        expect($r->json('conflicts.0.unattributedPayments.0'))->toMatchArray(['amount' => 300, 'paidOn' => '2027-01-01', 'note' => 'anticipo'])
            ->and($this->actingAs($u['owner'])->getJson("$api/padrinos")->json('data.0.committed'))->toBe(0)
            ->and(BudgetPayment::whereNotNull('padrino_id')->count())->toBe(0);
    });

    it('attributes the unowned payments to the padrino keeping amounts, dates and the original note', function () {
        [$u, $api] = paidSetup();
        $other = paidPadrino($this, $u['owner'], $api, 'Tía Marta');
        $item = paidItem($this, $u['owner'], $api, 'Banquete', 1000, [[300, ['note' => 'anticipo']], [200], [100, ['padrinoId' => $other]]]);
        $p = paidPadrino($this, $u['owner'], $api);
        $before = $this->actingAs($u['owner'])->getJson("$api/budget")->json('totals.paid');

        commit($this, $u['owner'], $api, $p, [['budgetItemId' => $item, 'amount' => 500, 'resolution' => 'attribute_payments']])
            ->assertOk()->assertJsonPath('data.paid', 500)->assertJsonPath('data.committed', 500);

        $payments = BudgetPayment::where('budget_item_id', $item)->orderBy('id')->get();
        expect($payments->pluck('padrino_id')->all())->toBe([$p, $p, $other])
            ->and($payments->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([300.0, 200.0, 100.0])
            ->and($payments[0]->paid_on->toDateString())->toBe('2027-01-01')
            ->and($payments[0]->note)->toBe('anticipo | Attributed to padrino Tío Raúl')
            ->and($payments[1]->note)->toBe('Attributed to padrino Tío Raúl')
            ->and($payments[2]->note)->toBeNull()
            ->and($this->actingAs($u['owner'])->getJson("$api/budget")->json('totals.paid'))->toBe($before);
        $totals = $this->actingAs($u['owner'])->getJson("$api/padrinos")->json('totals');
        expect($totals['paid'])->toBe(600);
    });

    it('allows an additional commitment up to what remains, and refuses above it or when fully paid', function () {
        [$u, $api] = paidSetup();
        $item = paidItem($this, $u['owner'], $api, 'Banquete', 1000, [[700]]);
        $full = paidItem($this, $u['owner'], $api, 'Pastel', 400, [[400]]);
        $p = paidPadrino($this, $u['owner'], $api);
        $add = fn ($id, $amount) => commit($this, $u['owner'], $api, $p, [['budgetItemId' => $id, 'amount' => $amount, 'resolution' => 'additional']]);

        $add($item, 300.01)->assertStatus(422)->assertJsonPath('code', 'commitment_exceeds_remaining')->assertJsonPath('remaining', 300)->assertJsonPath('budgetItemId', $item);
        $add($full, 1)->assertStatus(422)->assertJsonPath('remaining', 0);
        $add($item, 300)->assertOk()->assertJsonPath('data.committed', 300)->assertJsonPath('data.paid', 0);
        expect(BudgetPayment::whereNotNull('padrino_id')->count())->toBe(0);

        // Raising the same commitment only counts the increase against what remains.
        $add($item, 301)->assertOk();
    });

    it('does not ask again for a commitment that does not grow, and ignores resolution without conflict', function () {
        [$u, $api] = paidSetup();
        $item = paidItem($this, $u['owner'], $api, 'Banquete', 1000, [[700]]);
        $clean = paidItem($this, $u['owner'], $api, 'DJ', 500);
        $p = paidPadrino($this, $u['owner'], $api);
        commit($this, $u['owner'], $api, $p, [['budgetItemId' => $item, 'amount' => 300, 'resolution' => 'additional']])->assertOk();

        commit($this, $u['owner'], $api, $p, [['budgetItemId' => $item, 'amount' => 300]])->assertOk();
        commit($this, $u['owner'], $api, $p, [['budgetItemId' => $item, 'amount' => 200]])->assertOk();
        commit($this, $u['owner'], $api, $p, [['budgetItemId' => $clean, 'amount' => 100, 'resolution' => 'attribute_payments']])->assertOk();
        commit($this, $u['owner'], $api, $p, [['budgetItemId' => $clean, 'amount' => 100, 'resolution' => 'nope']])->assertJsonValidationErrors('commitments.0.resolution');
    });

    it('reports every conflicting item at once and is all-or-nothing', function () {
        [$u, $api] = paidSetup();
        $a = paidItem($this, $u['owner'], $api, 'Banquete', 1000, [[400]]);
        $b = paidItem($this, $u['owner'], $api, 'Pastel', 300, [[100]]);
        $ok = paidItem($this, $u['owner'], $api, 'DJ', 500);
        $p = paidPadrino($this, $u['owner'], $api);

        $r = commit($this, $u['owner'], $api, $p, [['budgetItemId' => $a, 'amount' => 100], ['budgetItemId' => $b, 'amount' => 100], ['budgetItemId' => $ok, 'amount' => 100]])->assertStatus(409);
        expect(collect($r->json('conflicts'))->pluck('budgetItemId')->sort()->values()->all())->toBe([$a, $b]);

        // One item attributed, the other beyond remaining: the 422 rolls back the attribution too.
        commit($this, $u['owner'], $api, $p, [
            ['budgetItemId' => $a, 'amount' => 100, 'resolution' => 'attribute_payments'],
            ['budgetItemId' => $b, 'amount' => 250, 'resolution' => 'additional'],
        ])->assertStatus(422);
        expect(BudgetPayment::whereNotNull('padrino_id')->count())->toBe(0)
            ->and($this->actingAs($u['owner'])->getJson("$api/padrinos")->json('data.0.committed'))->toBe(0);
    });

    it('warns, without blocking, when the commitments of an item add up to more than its cost', function () {
        [$u, $api] = paidSetup();
        $item = paidItem($this, $u['owner'], $api, 'DJ', 1000);
        $p1 = paidPadrino($this, $u['owner'], $api);
        $p2 = paidPadrino($this, $u['owner'], $api, 'Tía Marta');

        commit($this, $u['owner'], $api, $p1, [['budgetItemId' => $item, 'amount' => 800]])->assertOk()->assertJsonPath('warnings', []);
        commit($this, $u['owner'], $api, $p2, [['budgetItemId' => $item, 'amount' => 300]])->assertOk()
            ->assertJsonPath('warnings.0.code', 'commitments_exceed_cost')->assertJsonPath('warnings.0.budgetItemId', $item);

        // The agreed price, when there is one, is the cost.
        $this->actingAs($u['owner'])->putJson("$api/budget/items/$item", ['title' => 'DJ', 'estimatedCost' => 1000, 'agreedCost' => 1200])->assertOk();
        commit($this, $u['owner'], $api, $p2, [['budgetItemId' => $item, 'amount' => 300]])->assertOk()->assertJsonPath('warnings', []);
    });

    it('lets editors resolve, keeps viewers out and other events at 404', function () {
        [$u, $api] = paidSetup();
        [$u2, $api2] = paidSetup();
        $item = paidItem($this, $u['owner'], $api, 'Banquete', 1000, [[300]]);
        $p = paidPadrino($this, $u['owner'], $api);
        $foreign = paidPadrino($this, $u2['owner'], $api2);

        commit($this, $u['viewer'], $api, $p, [['budgetItemId' => $item, 'amount' => 100, 'resolution' => 'attribute_payments']])->assertForbidden();
        commit($this, $u['owner'], $api, $foreign, [])->assertNotFound();
        commit($this, $u['editor'], $api, $p, [['budgetItemId' => $item, 'amount' => 100, 'resolution' => 'attribute_payments']])->assertOk()->assertJsonPath('data.paid', 300);
    });
});
