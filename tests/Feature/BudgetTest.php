<?php

use App\Console\Commands\SendBudgetItemReminders;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\EventBudget;
use App\Models\EventFeature;
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

function budgetEvent(string $type = 'quinceanera', array $extra = []): array
{
    $event = Events::factory()->draft()->create(array_merge([
        'event_type_id' => EventType::where('slug', $type)->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
        'locale' => 'es',
        'currency' => 'USD',
    ], $extra));
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/budget"];
}

function addItem($test, $owner, string $url, array $extra = []): array
{
    return $test->actingAs($owner)->postJson("$url/items", array_merge(['title' => 'Salón', 'estimatedCost' => 1000], $extra))->assertCreated()->json('data');
}

describe('budget and categories', function () {
    it('is created on first read with the Spanish categories of the kind of event', function () {
        [, $u, $url] = budgetEvent('quinceanera');

        $data = $this->actingAs($u['owner'])->getJson($url)->assertOk()->json('data');

        expect(collect($data['categories'])->pluck('name')->all())->toContain('Salón', 'Vals y coreografía', 'Pastel', 'Otros')
            ->and($data['currency'])->toBe('USD')
            ->and($data['cap'])->toBeNull()
            ->and($data['totals'])->toMatchArray(['estimated' => 0, 'owed' => 0, 'paid' => 0, 'remaining' => 0, 'overCap' => false]);
        expect(EventBudget::count())->toBe(1);
        $this->actingAs($u['owner'])->getJson($url);
        expect(EventBudget::count())->toBe(1)->and(BudgetCategory::count())->toBe(13);
    });

    it('uses the wedding catalog, and English for English events', function () {
        [, $u, $url] = budgetEvent('wedding', ['locale' => 'en']);

        $names = collect($this->actingAs($u['owner'])->getJson($url)->json('data.categories'))->pluck('name')->all();

        expect($names)->toContain('Venue', 'Ceremony', 'Attire')->not->toContain('Salón');
    });

    it('sets and clears the cap, and flags going over it', function () {
        [, $u, $url] = budgetEvent();
        addItem($this, $u['owner'], $url, ['estimatedCost' => 1500]);

        $this->actingAs($u['owner'])->putJson($url, ['cap' => 1000])->assertOk()
            ->assertJsonPath('data.cap', 1000)->assertJsonPath('data.totals.overCap', true)->assertJsonPath('data.totals.capLeft', -500);
        $this->actingAs($u['owner'])->putJson($url, ['cap' => null])->assertOk()->assertJsonPath('data.cap', null)->assertJsonPath('data.totals.overCap', false);
        $this->actingAs($u['owner'])->putJson($url, ['cap' => -5])->assertJsonValidationErrors('cap');
        $this->actingAs($u['owner'])->putJson($url, [])->assertJsonValidationErrors('cap');
    });

    it('adds, renames and removes categories, but not one that still has items', function () {
        [, $u, $url] = budgetEvent();
        $created = $this->actingAs($u['owner'])->postJson("$url/categories", ['name' => ' Piñata '])->assertCreated()->json('data.categories');
        $mine = collect($created)->firstWhere('name', 'Piñata');

        $this->actingAs($u['owner'])->putJson("$url/categories/{$mine['id']}", ['name' => 'Piñatas'])->assertOk();
        $this->actingAs($u['owner'])->postJson("$url/categories", ['name' => ''])->assertJsonValidationErrors('name');

        addItem($this, $u['owner'], $url, ['categoryId' => $mine['id']]);
        $this->actingAs($u['owner'])->deleteJson("$url/categories/{$mine['id']}")->assertStatus(422);
        BudgetItem::query()->delete();
        $this->actingAs($u['owner'])->deleteJson("$url/categories/{$mine['id']}")->assertOk();
    });
});

describe('items and payments', function () {
    it('computes what is owed, paid and left, and the status of each item', function () {
        [, $u, $url] = budgetEvent();
        $item = addItem($this, $u['owner'], $url, ['estimatedCost' => 1000, 'dueDate' => now()->addDays(30)->toDateString()]);
        expect($item)->toMatchArray(['owed' => 1000, 'paid' => 0, 'remaining' => 1000, 'status' => 'unpaid']);

        $partial = $this->actingAs($u['owner'])->postJson("$url/items/{$item['id']}/payments", ['amount' => 400, 'paidOn' => '2027-01-10', 'note' => 'Anticipo'])->assertCreated()->json('data');
        expect($partial)->toMatchArray(['paid' => 400, 'remaining' => 600, 'status' => 'partial']);

        // The agreed price replaces the estimate as what is owed.
        $agreed = $this->actingAs($u['owner'])->putJson("$url/items/{$item['id']}", ['title' => 'Salón', 'estimatedCost' => 1000, 'agreedCost' => 1200, 'dueDate' => now()->addDays(30)->toDateString()])->assertOk()->json('data');
        expect($agreed)->toMatchArray(['owed' => 1200, 'remaining' => 800]);

        $paid = $this->actingAs($u['owner'])->postJson("$url/items/{$item['id']}/payments", ['amount' => 800, 'paidOn' => '2027-02-01'])->assertCreated()->json('data');
        expect($paid)->toMatchArray(['paid' => 1200, 'remaining' => 0, 'status' => 'paid'])->and($paid['payments'])->toHaveCount(2);
    });

    it('marks an item overdue when the date passed with a balance, and not when it is paid', function () {
        [, $u, $url] = budgetEvent();
        $late = addItem($this, $u['owner'], $url, ['dueDate' => now()->subDays(3)->toDateString()]);
        $done = addItem($this, $u['owner'], $url, ['title' => 'Vestido', 'dueDate' => now()->subDays(3)->toDateString()]);
        $this->actingAs($u['owner'])->postJson("$url/items/{$done['id']}/payments", ['amount' => 1000, 'paidOn' => '2027-01-01'])->assertCreated();

        $rows = collect($this->actingAs($u['owner'])->getJson("$url/items")->json('data'))->keyBy('id');
        $summary = $this->actingAs($u['owner'])->getJson($url)->json('data');

        expect($rows[$late['id']]['status'])->toBe('overdue')->and($rows[$done['id']]['status'])->toBe('paid')
            ->and($summary['totals']['overdueCount'])->toBe(1);
    });

    it('totals by category and for the whole budget', function () {
        [, $u, $url] = budgetEvent();
        $cats = collect($this->actingAs($u['owner'])->getJson($url)->json('data.categories'));
        $venue = $cats->firstWhere('name', 'Salón')['id'];
        $cake = $cats->firstWhere('name', 'Pastel')['id'];
        $a = addItem($this, $u['owner'], $url, ['categoryId' => $venue, 'estimatedCost' => 1000]);
        addItem($this, $u['owner'], $url, ['title' => 'Pastel', 'categoryId' => $cake, 'estimatedCost' => 300, 'agreedCost' => 350]);
        addItem($this, $u['owner'], $url, ['title' => 'Suelto', 'estimatedCost' => 50]);
        $this->actingAs($u['owner'])->postJson("$url/items/{$a['id']}/payments", ['amount' => 250, 'paidOn' => '2027-01-01'])->assertCreated();

        $data = $this->actingAs($u['owner'])->getJson($url)->json('data');

        expect($data['totals'])->toMatchArray(['estimated' => 1350, 'owed' => 1400, 'paid' => 250, 'remaining' => 1150])
            ->and(collect($data['categories'])->firstWhere('id', $venue)['totals'])->toMatchArray(['owed' => 1000, 'paid' => 250, 'remaining' => 750])
            ->and(collect($data['categories'])->firstWhere('id', $cake)['totals']['owed'])->toBe(350)
            ->and($data['uncategorized'])->toMatchArray(['itemCount' => 1])
            ->and($data['uncategorized']['totals']['owed'])->toBe(50);
    });

    it('edits and deletes payments, and returns the item with its new numbers', function () {
        [, $u, $url] = budgetEvent();
        $item = addItem($this, $u['owner'], $url);
        $withPayment = $this->actingAs($u['owner'])->postJson("$url/items/{$item['id']}/payments", ['amount' => 100, 'paidOn' => '2027-01-01'])->json('data');
        $paymentId = $withPayment['payments'][0]['id'];

        $this->actingAs($u['owner'])->putJson("$url/items/{$item['id']}/payments/$paymentId", ['amount' => 300, 'paidOn' => '2027-01-02', 'note' => 'Ajustado'])
            ->assertOk()->assertJsonPath('data.paid', 300);
        $this->actingAs($u['owner'])->deleteJson("$url/items/{$item['id']}/payments/$paymentId")->assertOk()->assertJsonPath('data.paid', 0);
    });

    it('validates items and payments with 422 and field errors', function () {
        [, $u, $url] = budgetEvent();
        $item = addItem($this, $u['owner'], $url);

        $this->actingAs($u['owner'])->postJson("$url/items", ['title' => '', 'estimatedCost' => -1])->assertStatus(422)->assertJsonValidationErrors(['title', 'estimatedCost']);
        $this->actingAs($u['owner'])->postJson("$url/items", ['title' => 'X', 'estimatedCost' => 1, 'dueDate' => '10/02/2027'])->assertJsonValidationErrors('dueDate');
        $this->actingAs($u['owner'])->postJson("$url/items", ['title' => 'X', 'estimatedCost' => 1, 'categoryId' => 999999])->assertJsonValidationErrors('categoryId');
        $this->actingAs($u['owner'])->postJson("$url/items/{$item['id']}/payments", ['amount' => 0, 'paidOn' => '2027-01-01'])->assertJsonValidationErrors('amount');
        $this->actingAs($u['owner'])->postJson("$url/items/{$item['id']}/payments", ['amount' => 5])->assertJsonValidationErrors('paidOn');
    });

    it('only accepts categories of the same event', function () {
        [, $u, $url] = budgetEvent();
        [, $u2, $url2] = budgetEvent();
        $foreign = collect($this->actingAs($u2['owner'])->getJson($url2)->json('data.categories'))->first()['id'];
        $this->actingAs($u['owner'])->getJson($url);

        $this->actingAs($u['owner'])->postJson("$url/items", ['title' => 'X', 'estimatedCost' => 1, 'categoryId' => $foreign])->assertJsonValidationErrors('categoryId');
    });
});

describe('access', function () {
    it('keeps viewers read-only and strangers out', function () {
        [, $u, $url] = budgetEvent();
        $item = addItem($this, $u['owner'], $url);

        $this->actingAs($u['viewer'])->getJson($url)->assertOk();
        $this->actingAs($u['viewer'])->getJson("$url/items")->assertOk();
        $this->actingAs($u['viewer'])->putJson($url, ['cap' => 1])->assertForbidden();
        $this->actingAs($u['viewer'])->postJson("$url/items", ['title' => 'X', 'estimatedCost' => 1])->assertForbidden();
        $this->actingAs($u['viewer'])->postJson("$url/items/{$item['id']}/payments", ['amount' => 1, 'paidOn' => '2027-01-01'])->assertForbidden();
        $this->actingAs($u['stranger'])->getJson($url)->assertNotFound();
        $this->actingAs($u['stranger'])->getJson("$url/export")->assertNotFound();
    });

    it('does not touch items, categories or payments of another event', function () {
        [, $u, $url] = budgetEvent();
        [, $u2, $url2] = budgetEvent();
        $foreign = addItem($this, $u2['owner'], $url2);
        $foreignPayment = $this->actingAs($u2['owner'])->postJson("$url2/items/{$foreign['id']}/payments", ['amount' => 10, 'paidOn' => '2027-01-01'])->json('data.payments.0.id');
        $foreignCategory = collect($this->actingAs($u2['owner'])->getJson($url2)->json('data.categories'))->first()['id'];
        $this->actingAs($u['owner'])->getJson($url);

        $this->actingAs($u['owner'])->putJson("$url/items/{$foreign['id']}", ['title' => 'X', 'estimatedCost' => 1])->assertNotFound();
        $this->actingAs($u['owner'])->deleteJson("$url/items/{$foreign['id']}")->assertNotFound();
        $this->actingAs($u['owner'])->postJson("$url/items/{$foreign['id']}/payments", ['amount' => 1, 'paidOn' => '2027-01-01'])->assertNotFound();
        $this->actingAs($u['owner'])->deleteJson("$url/items/{$foreign['id']}/payments/$foreignPayment")->assertNotFound();
        $this->actingAs($u['owner'])->putJson("$url/categories/$foreignCategory", ['name' => 'X'])->assertNotFound();
        expect(BudgetItem::find($foreign['id']))->not->toBeNull();
    });

    it('rejects a payment id that belongs to another item of the same event', function () {
        [, $u, $url] = budgetEvent();
        $a = addItem($this, $u['owner'], $url);
        $b = addItem($this, $u['owner'], $url, ['title' => 'B']);
        $payment = $this->actingAs($u['owner'])->postJson("$url/items/{$a['id']}/payments", ['amount' => 10, 'paidOn' => '2027-01-01'])->json('data.payments.0.id');

        $this->actingAs($u['owner'])->deleteJson("$url/items/{$b['id']}/payments/$payment")->assertNotFound();
    });
});

describe('export and reminders', function () {
    it('exports a CSV with one row per item, in the event language', function () {
        [, $u, $url] = budgetEvent();
        $item = addItem($this, $u['owner'], $url, ['title' => 'Salón, Cristal', 'vendorName' => 'Cristal', 'estimatedCost' => 1000]);
        $this->actingAs($u['owner'])->postJson("$url/items/{$item['id']}/payments", ['amount' => 400, 'paidOn' => '2027-01-01'])->assertCreated();

        $response = $this->actingAs($u['owner'])->get("$url/export")->assertOk();
        $csv = $response->streamedContent();

        expect($response->headers->get('Content-Type'))->toContain('text/csv')
            ->and($csv)->toContain('Categoría,Concepto,Proveedor')
            ->and($csv)->toContain('"Salón, Cristal"')->toContain('Parcial');
    });

    it('reminds only items with a balance', function () {
        [$event, $u, $url] = budgetEvent();
        $due = now()->addDays(3)->toDateString();
        $open = addItem($this, $u['owner'], $url, ['title' => 'Abierto', 'dueDate' => $due]);
        $closed = addItem($this, $u['owner'], $url, ['title' => 'Cerrado', 'dueDate' => $due]);
        $this->actingAs($u['owner'])->postJson("$url/items/{$closed['id']}/payments", ['amount' => 1000, 'paidOn' => '2027-01-01'])->assertCreated();

        $this->artisan('budget-item:reminders', ['--dry-run' => true])
            ->expectsOutputToContain('"Abierto"')->doesntExpectOutputToContain('"Cerrado"')->assertSuccessful();
        expect(SendBudgetItemReminders::THRESHOLD_DAYS)->toContain(3);
    });
});
