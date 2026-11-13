<?php

use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
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

function customerEvent(string $email = 'ana@example.com', array $attrs = []): array
{
    $owner = User::factory()->create(['email' => $email]);
    $event = Events::factory()->draft()->create($attrs + [
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner];
}

function staff(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    return $admin;
}

it('is closed to organizers and to anonymous visitors', function () {
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

it('counts customers and leaves out staff and demo accounts unless asked', function () {
    customerEvent('ana@example.com');
    customerEvent('demo@cwm.test');

    $admin = staff();
    $mine = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk();
    $all = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?internal=1')->assertOk();

    expect($mine->json('data.kpis.eventsCreated.value'))->toBe(1)
        ->and($mine->json('data.kpis.signups.value'))->toBe(1)
        ->and($all->json('data.kpis.eventsCreated.value'))->toBe(2)
        ->and($all->json('data.includeInternal'))->toBeTrue();
});

it('adds up revenue and refunds in the period', function () {
    [$event, $owner] = customerEvent();
    $plan = EventPlan::where('slug', 'premium')->first();
    $base = ['event_id' => $event->id, 'user_id' => $owner->id, 'event_plan_id' => $plan->id, 'gateway' => 'manual', 'list_price_cents' => 9900, 'currency' => 'USD'];
    EventPurchase::create($base + ['amount_cents' => 7900, 'status' => 'paid', 'paid_at' => now()->subDays(3)]);
    EventPurchase::create($base + ['amount_cents' => 9900, 'status' => 'paid', 'paid_at' => now()->subDays(45)]);
    EventPurchase::create($base + ['amount_cents' => 5000, 'status' => 'refunded', 'paid_at' => now()->subDays(5), 'refunded_at' => now()->subDay()]);

    $kpis = $this->actingAs(staff())->getJson('/api/v1/admin/dashboard?days=30')->json('data.kpis');

    expect($kpis['paid'])->toMatchArray(['value' => 1, 'previous' => 1])
        ->and($kpis['revenueCents']['value'])->toBe(7900)
        ->and($kpis['refundedCents']['value'])->toBe(5000);
});

it('follows the accounts of the period down the funnel', function () {
    [$withGuests, $a] = customerEvent('a@example.com');
    Guest::factory()->count(2)->create(['event_id' => $withGuests->id]);
    [$published] = customerEvent('b@example.com', ['published_at' => now(), 'status' => 'published']);
    User::factory()->create(['email' => 'c@example.com']); // signed up, did nothing

    $funnel = collect($this->actingAs(staff())->getJson('/api/v1/admin/dashboard')->json('data.funnel'))->pluck('count', 'key');

    expect($funnel['signups'])->toBe(3)->and($funnel['event'])->toBe(2)->and($funnel['guests'])->toBe(1)->and($funnel['published'])->toBe(1)->and($funnel['paid'])->toBe(0);
});

it('lists what needs attention', function () {
    [$soon] = customerEvent('a@example.com', ['start_date' => now()->addDays(5)]);
    [$big] = customerEvent('b@example.com');
    Guest::factory()->count(31)->create(['event_id' => $big->id]);
    $stuck = EventPurchase::create(['event_id' => $big->id, 'user_id' => User::where('email', 'b@example.com')->value('id'), 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'fake', 'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => 'pending']);
    EventPurchase::whereKey($stuck->id)->toBase()->update(['created_at' => now()->subHours(3)]);
    Coupon::create(['code' => 'SOON', 'kind' => 'fixed_price', 'value' => 7900, 'expires_at' => now()->addDays(2)]);

    $types = collect($this->actingAs(staff())->getJson('/api/v1/admin/dashboard')->json('data.attention'))->keyBy('type');

    expect($types->keys()->all())->toEqualCanonicalizing(['stuck_purchases', 'over_free_limit', 'upcoming_draft', 'coupons_ending'])
        ->and($types['upcoming_draft']['examples'][0]['eventId'])->toBe($soon->id);
});

it('rejects a period it does not know', function () {
    $this->actingAs(staff())->getJson('/api/v1/admin/dashboard?days=5')->assertStatus(422);
});

it('gives a value per day for the period and the one before, zeros included', function () {
    [$event, $owner] = customerEvent();
    $plan = EventPlan::where('slug', 'premium')->first();
    $base = ['event_id' => $event->id, 'user_id' => $owner->id, 'event_plan_id' => $plan->id, 'gateway' => 'manual', 'list_price_cents' => 9900, 'currency' => 'USD', 'status' => 'paid'];
    EventPurchase::create($base + ['amount_cents' => 7900, 'paid_at' => now()->subDays(2)]);
    EventPurchase::create($base + ['amount_cents' => 9900, 'paid_at' => now()->subDays(9)]);

    $series = $this->actingAs(staff())->getJson('/api/v1/admin/dashboard?days=7')->json('data.series');

    expect($series['dates'])->toHaveCount(7)->and($series['dates'][6])->toBe(now()->toDateString())
        ->and($series['metrics']['revenueCents']['current'])->toHaveCount(7)
        ->and($series['metrics']['revenueCents']['current'][4])->toBe(7900)      // two days ago
        ->and(array_sum($series['metrics']['revenueCents']['current']))->toBe(7900)
        ->and(array_sum($series['metrics']['revenueCents']['previous']))->toBe(9900)
        ->and($series['metrics']['paid']['current'][4])->toBe(1);
});

it('splits events by type, plan and stage, and lists the next eight weeks', function () {
    customerEvent('a@example.com', ['start_date' => now()->addDays(10)]);
    customerEvent('b@example.com', ['start_date' => now()->addDays(10), 'published_at' => now(), 'status' => 'published', 'invitation_stage' => 'save_the_date']);

    $data = $this->actingAs(staff())->getJson('/api/v1/admin/dashboard')->json('data');
    $stage = collect($data['mix']['stage'])->pluck('count', 'key');

    expect($data['mix']['type'][0])->toMatchArray(['key' => 'quinceanera', 'count' => 2])
        ->and($stage['draft'])->toBe(1)->and($stage['save_the_date'])->toBe(1)
        ->and($data['upcomingWeeks'])->toHaveCount(8)
        ->and(collect($data['upcomingWeeks'])->sum('draft'))->toBe(1)
        ->and(collect($data['upcomingWeeks'])->sum('published'))->toBe(1);
});
