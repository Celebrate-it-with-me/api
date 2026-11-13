<?php

use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function revStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function revPurchase(array $attrs = [], string $eventName = 'Los XV de Ana', string $status = 'draft', ?User $buyer = null): EventPurchase
{
    $event = Events::factory()->create([
        'event_name' => $eventName, 'status' => $status,
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
    ]);
    $buyer ??= User::factory()->create();

    return EventPurchase::create($attrs + [
        'event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => 'paid', 'paid_at' => now(),
        'gateway_payment_id' => 'pi_3Abc123SECRETXYZ9',
    ]);
}

it('keeps everyone but superadmin out', function () {
    $p = revPurchase();
    $urls = ['/api/v1/admin/revenue/purchases', "/api/v1/admin/revenue/purchases/{$p->id}", '/api/v1/admin/revenue/pricing'];

    foreach ($urls as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
    foreach ($urls as $url) {
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->actingAs(revStaff('support'))->getJson($url)->assertForbidden();
        $this->actingAs(revStaff('superadmin'))->getJson($url)->assertOk();
    }
});

it('lists purchases with a summary of the filtered set', function () {
    revPurchase();
    revPurchase(['amount_cents' => 7900, 'is_founder' => true, 'gateway' => 'manual'], 'Boda de Luz');
    revPurchase(['status' => 'refunded', 'refunded_at' => now()], 'XV Sofia');
    revPurchase(['status' => 'pending', 'paid_at' => null], 'XV Pending');
    $admin = revStaff('superadmin');

    $all = $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases')->assertOk();
    $all->assertJsonPath('summary.count', 4)->assertJsonPath('summary.paidCount', 2)->assertJsonPath('summary.revenueCents', 17800)
        ->assertJsonPath('summary.refundedCount', 1)->assertJsonPath('summary.refundedCents', 9900)
        ->assertJsonPath('meta.total', 4)->assertJsonCount(4, 'data');

    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?status=paid&founder=1')
        ->assertJsonPath('summary.count', 1)->assertJsonPath('data.0.event.name', 'Boda de Luz')->assertJsonPath('data.0.gateway', 'manual');
    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?gateway=manual')->assertJsonPath('summary.count', 1);
    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?search=sofia')->assertJsonPath('summary.refundedCount', 1)->assertJsonPath('summary.count', 1);
});

it('filters by code, buyer email and dates', function () {
    $coupon = Coupon::create(['code' => 'FUND79', 'kind' => 'fixed_price', 'value' => 7900, 'is_founder' => true]);
    $buyer = User::factory()->create(['email' => 'marisol@example.com']);
    revPurchase(['coupon_id' => $coupon->id, 'amount_cents' => 7900, 'paid_at' => '2026-11-10 12:00:00'], 'Con codigo', 'draft', $buyer);
    $old = revPurchase(['paid_at' => '2026-10-01 12:00:00']);
    $old->forceFill(['created_at' => '2026-10-01 12:00:00'])->save();
    $admin = revStaff('superadmin');

    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?coupon=fund79')->assertJsonPath('summary.count', 1);
    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?search=MARISOL@example')->assertJsonPath('summary.count', 1)->assertJsonPath('data.0.couponCode', 'FUND79');
    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?paidFrom=2026-11-01&paidTo=2026-11-10')->assertJsonPath('summary.count', 1);
    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?createdTo=2026-10-02')->assertJsonPath('summary.count', 1)->assertJsonPath('data.0.id', $old->id);
    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?paidFrom=2026-11-10&paidTo=2026-11-01')->assertStatus(422);
    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?status=bogus')->assertStatus(422);
});

it('paginates, and a listing does not run more queries as it grows', function () {
    $admin = revStaff('superadmin');
    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?perPage=50')->assertOk();

        return count(DB::getQueryLog());
    };

    foreach (range(1, 3) as $i) {
        revPurchase([], "Evento {$i}");
    }
    $count(); // warm-up (permission cache, etc.)
    $small = $count();
    foreach (range(4, 12) as $i) {
        revPurchase(['gateway' => 'manual', 'created_by' => $admin->id], "Evento {$i}");
    }
    expect($count())->toBe($small);

    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases?perPage=5&page=3')
        ->assertJsonPath('meta.lastPage', 3)->assertJsonCount(2, 'data');
});

it('shows one purchase with its timeline, who recorded it and no whole gateway ids', function () {
    $admin = revStaff('superadmin');
    $p = revPurchase(['gateway' => 'manual', 'created_by' => $admin->id, 'notes' => 'Zelle 11/13', 'status' => 'refunded', 'refunded_at' => now(), 'amount_cents' => 5000]);

    $res = $this->actingAs($admin)->getJson("/api/v1/admin/revenue/purchases/{$p->id}")->assertOk()
        ->assertJsonPath('data.registeredBy.id', $admin->id)->assertJsonPath('data.notes', 'Zelle 11/13')
        ->assertJsonPath('data.discountCents', 4900)->assertJsonPath('data.gatewayRef', '…XYZ9')
        ->assertJsonPath('data.canRefund', false);

    expect(collect($res->json('data.timeline'))->pluck('type')->all())->toBe(['created', 'paid', 'refunded']);
    expect($res->getContent())->not->toContain('pi_3Abc123');
    $this->actingAs($admin)->getJson('/api/v1/admin/revenue/purchases/999999')->assertNotFound();
});

it('says whether a purchase can still be refunded', function () {
    $admin = revStaff('superadmin');
    $open = revPurchase([], 'Borrador', 'draft');
    $live = revPurchase([], 'Publicado', 'published');

    $this->actingAs($admin)->getJson("/api/v1/admin/revenue/purchases/{$open->id}")->assertJsonPath('data.canRefund', true);
    $this->actingAs($admin)->getJson("/api/v1/admin/revenue/purchases/{$live->id}")->assertJsonPath('data.canRefund', false);
});

it('reports the current price and counts', function () {
    revPurchase();
    revPurchase(['status' => 'pending']);
    Coupon::create(['code' => 'A', 'kind' => 'fixed_price', 'value' => 100]);
    Coupon::create(['code' => 'B', 'kind' => 'fixed_price', 'value' => 100, 'is_active' => false]);

    $this->actingAs(revStaff('superadmin'))->getJson('/api/v1/admin/revenue/pricing')->assertOk()
        ->assertJsonPath('data.plan.priceCents', 9900)->assertJsonPath('data.plan.currency', 'USD')->assertJsonPath('data.plan.maxGuests', 300)
        ->assertJsonPath('data.purchases.total', 2)->assertJsonPath('data.purchases.paid', 1)
        ->assertJsonPath('data.coupons.total', 2)->assertJsonPath('data.coupons.active', 1);
});
