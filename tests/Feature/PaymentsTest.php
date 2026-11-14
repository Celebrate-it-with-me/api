<?php

use App\Http\Services\AppServices\GuestLimit;
use App\Http\Services\Payments\FakeGateway;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EventPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function ownedEvent(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $user->id, 'role_id' => Role::where('name', $role)->value('id')]);

    return [$event, $user];
}

function adminUser(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');

    return $admin;
}

function sendWebhook($test, array $body, ?string $sig = null)
{
    $raw = json_encode($body);

    return $test->call('POST', '/api/v1/app/payments/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $sig ?? FakeGateway::sign($raw),
    ], $raw);
}

it('quotes the list price, and a founder code price', function () {
    [$event, $owner] = ownedEvent();
    Coupon::create(['code' => 'FUND79', 'kind' => 'fixed_price', 'value' => 7900, 'is_founder' => true]);

    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote")
        ->assertOk()->assertJsonPath('data.amountCents', 9900)->assertJsonPath('data.maxGuests', 300);
    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=fund79")
        ->assertOk()->assertJsonPath('data.amountCents', 7900);
    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=NOPE")->assertStatus(422);
});

it('only the owner can start a purchase', function () {
    [$event, $editor] = ownedEvent('editor');

    $this->actingAs($editor)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout")->assertForbidden();
});

it('unlocks the event once the signed webhook arrives, and only once', function () {
    [$event, $owner] = ownedEvent();
    Coupon::create(['code' => 'FUND79', 'kind' => 'fixed_price', 'value' => 7900, 'is_founder' => true, 'max_uses' => 5]);

    $res = $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout", ['code' => 'FUND79'])->assertCreated();
    $purchase = EventPurchase::find($res->json('data.purchaseId'));
    expect($purchase)->status->toBe('pending')->amount_cents->toBe(7900)->is_founder->toBeTrue();

    $body = ['type' => 'payment.succeeded', 'session' => $purchase->gateway_session_id, 'payment' => 'pay_1'];
    sendWebhook($this, $body)->assertOk();
    sendWebhook($this, $body)->assertOk();

    expect($event->fresh()->eventPlan->slug)->toBe('premium')
        ->and($purchase->fresh())->status->toBe('paid')
        ->and(Coupon::first()->times_used)->toBe(1);
    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/status")->assertJsonPath('data.unlocked', true);
    $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout")->assertStatus(422);
});

it('unlocks at once when a code brings the price to zero', function () {
    [$event, $owner] = ownedEvent();
    Coupon::create(['code' => 'GIFT', 'kind' => 'percent_off', 'value' => 100]);

    $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout", ['code' => 'GIFT'])->assertCreated();

    expect($event->fresh()->eventPlan->slug)->toBe('premium');
});

it('rejects a webhook with a bad signature', function () {
    [$event, $owner] = ownedEvent();
    $res = $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout")->assertCreated();
    $purchase = EventPurchase::find($res->json('data.purchaseId'));

    sendWebhook($this, ['type' => 'payment.succeeded', 'session' => $purchase->gateway_session_id], 'bad')->assertStatus(400);

    expect($event->fresh()->eventPlan->slug)->toBe('basic');
});

it('does not accept expired, used up or inactive codes', function () {
    [$event, $owner] = ownedEvent();
    Coupon::create(['code' => 'OLD', 'kind' => 'fixed_price', 'value' => 100, 'expires_at' => now()->subDay()]);
    Coupon::create(['code' => 'USED', 'kind' => 'fixed_price', 'value' => 100, 'max_uses' => 1, 'times_used' => 1]);
    Coupon::create(['code' => 'OFF', 'kind' => 'fixed_price', 'value' => 100, 'is_active' => false]);

    foreach (['OLD', 'USED', 'OFF'] as $code) {
        $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote?code=$code")->assertStatus(422);
    }
});

it('lifts the guest limit from 30 to 300 when unlocked', function () {
    [$event, $owner] = ownedEvent();
    $limit = app(GuestLimit::class);
    expect($limit->limitFor($event))->toBe(30);

    $res = $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout")->assertCreated();
    sendWebhook($this, ['type' => 'payment.succeeded', 'session' => EventPurchase::find($res->json('data.purchaseId'))->gateway_session_id]);

    expect($limit->limitFor($event->fresh()))->toBe(300);
});

describe('admin', function () {
    it('records a manual payment (Zelle) and unlocks the event', function () {
        [$event, $owner] = ownedEvent();

        $this->actingAs(adminUser())->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['userId' => $owner->id, 'amountCents' => 9900, 'notes' => 'Zelle 11/13', 'reason' => 'Zelle received'])
            ->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.gateway', 'manual');

        expect($event->fresh()->eventPlan->slug)->toBe('premium');
    });

    it('refunds in full only while the event is not published', function () {
        [$event, $owner] = ownedEvent();
        $admin = adminUser();
        $id = $this->actingAs($admin)->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['userId' => $owner->id, 'amountCents' => 9900, 'reason' => 'Zelle received'])->json('data.id');

        $event->update(['status' => 'published']);
        $this->actingAsReauthed($admin)->postJson("/api/v1/admin/payments/{$id}/refund", ['reason' => 'Asked for it'])->assertStatus(422);

        $event->update(['status' => 'draft']);
        $this->actingAsReauthed($admin)->postJson("/api/v1/admin/payments/{$id}/refund", ['reason' => 'Asked for it'])->assertOk()->assertJsonPath('data.status', 'refunded');
        expect($event->fresh()->eventPlan->slug)->toBe('basic');
    });

    it('changes the price and creates codes', function () {
        $admin = adminUser();

        $this->actingAsReauthed($admin)->putJson('/api/v1/admin/payments/price', ['priceCents' => 7900, 'reason' => 'Launch price'])->assertOk();
        $this->actingAsReauthed($admin)->postJson('/api/v1/admin/payments/coupons', ['code' => 'fundador1', 'kind' => 'fixed_price', 'value' => 5900, 'isFounder' => true, 'maxUses' => 10, 'reason' => 'Founders'])
            ->assertCreated()->assertJsonPath('data.code', 'FUNDADOR1');

        expect(EventPlan::where('slug', 'premium')->value('price_cents'))->toBe(7900);
    });

    it('is closed to organizers', function () {
        [$event, $owner] = ownedEvent();

        $this->actingAs($owner)->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->actingAsReauthed($owner)->putJson('/api/v1/admin/payments/price', ['priceCents' => 1, 'reason' => 'Trying it out'])->assertForbidden();
    });
});

it('does not overwrite an edited price when seeded again', function () {
    EventPlan::where('slug', 'premium')->update(['price_cents' => 7900]);

    $this->seed(EventPlanSeeder::class);

    expect(EventPlan::where('slug', 'premium')->value('price_cents'))->toBe(7900);
});

it('refuses every webhook when no secret is configured and ignores the old public default (S-03)', function () {
    [$event, $owner] = ownedEvent();
    $res = $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout")->assertCreated();
    $purchase = EventPurchase::find($res->json('data.purchaseId'));
    $raw = json_encode(['type' => 'payment.succeeded', 'session' => $purchase->gateway_session_id]);

    // Signed with the secret that used to be the default in the repository.
    sendWebhook($this, json_decode($raw, true), hash_hmac('sha256', $raw, 'local-webhook-secret'))->assertStatus(400);

    config(['services.payments.webhook_secret' => null]);
    $forged = hash_hmac('sha256', $raw, '');
    $this->call('POST', '/api/v1/app/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $forged], $raw)
        ->assertStatus(503);

    expect($event->fresh()->eventPlan->slug)->toBe('basic');
});

it('rejects a webhook whose amount does not match the purchase', function () {
    [$event, $owner] = ownedEvent();
    $res = $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout")->assertCreated();
    $purchase = EventPurchase::find($res->json('data.purchaseId'));

    sendWebhook($this, ['type' => 'payment.succeeded', 'session' => $purchase->gateway_session_id, 'amount' => 1])->assertStatus(400);
    expect($purchase->fresh()->status)->toBe('pending');

    sendWebhook($this, ['type' => 'payment.succeeded', 'session' => $purchase->gateway_session_id, 'amount' => $purchase->amount_cents, 'currency' => $purchase->currency])->assertOk();
    expect($purchase->fresh()->status)->toBe('paid');
});

it('never resolves the fake gateway in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => app(\App\Http\Services\Payments\PurchaseService::class)->gateway('fake'))->toThrow(RuntimeException::class);
});

it('does not let simultaneous checkouts exceed a coupon cap (S-19)', function () {
    Coupon::create(['code' => 'ONE', 'kind' => 'fixed_price', 'value' => 5000, 'max_uses' => 1]);
    [$eventA, $ownerA] = ownedEvent();
    [$eventB, $ownerB] = ownedEvent();

    $this->actingAs($ownerA)->postJson("/api/v1/app/event/{$eventA->id}/purchase/checkout", ['code' => 'ONE'])->assertCreated();
    // The first purchase is still pending (not paid): it already holds the only use.
    $this->actingAs($ownerB)->postJson("/api/v1/app/event/{$eventB->id}/purchase/checkout", ['code' => 'ONE'])->assertStatus(422);

    expect(EventPurchase::where('event_id', $eventB->id)->exists())->toBeFalse();
});

it('releases the held use after an hour without payment', function () {
    Coupon::create(['code' => 'ONE', 'kind' => 'fixed_price', 'value' => 5000, 'max_uses' => 1]);
    [$eventA, $ownerA] = ownedEvent();
    [$eventB, $ownerB] = ownedEvent();

    $this->actingAs($ownerA)->postJson("/api/v1/app/event/{$eventA->id}/purchase/checkout", ['code' => 'ONE'])->assertCreated();
    $this->travel(61)->minutes();

    $this->actingAs($ownerB)->postJson("/api/v1/app/event/{$eventB->id}/purchase/checkout", ['code' => 'ONE'])->assertCreated();
});
