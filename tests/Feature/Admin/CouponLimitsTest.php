<?php

use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function couponStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function limitedCoupon(): Coupon
{
    return Coupon::create([
        'code' => 'LIMITED', 'kind' => 'fixed_price', 'value' => 7900,
        'max_uses' => 50, 'expires_at' => '2027-01-31 00:00:00',
    ]);
}

describe('updating coupon limits', function () {
    it('removes the usage cap with an explicit null and leaves the expiry alone', function () {
        $coupon = limitedCoupon();
        $this->actingAsReauthed(couponStaff(AdminPermissions::SUPERADMIN))
            ->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['maxUses' => null, 'reason' => 'Open to everyone'])
            ->assertOk();

        $coupon->refresh();
        expect($coupon->max_uses)->toBeNull()->and($coupon->expires_at)->not->toBeNull()->and($coupon->is_active)->toBeTrue();

        $log = AdminAuditLog::where('action', 'coupon.update')->firstOrFail();
        expect($log->before['max_uses'])->toBe(50)->and($log->after['max_uses'])->toBeNull();
    });

    it('removes the expiry with an explicit null and leaves the cap alone', function () {
        $coupon = limitedCoupon();
        $this->actingAsReauthed(couponStaff(AdminPermissions::SUPERADMIN))
            ->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['expiresAt' => null, 'reason' => 'No deadline'])
            ->assertOk();

        $coupon->refresh();
        expect($coupon->expires_at)->toBeNull()->and($coupon->max_uses)->toBe(50);

        $log = AdminAuditLog::where('action', 'coupon.update')->firstOrFail();
        expect($log->before['expires_at'])->not->toBeNull()->and($log->after['expires_at'])->toBeNull();
    });

    it('does not touch absent fields', function () {
        $coupon = limitedCoupon();
        $this->actingAsReauthed(couponStaff(AdminPermissions::SUPERADMIN))
            ->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['isActive' => false, 'reason' => 'Campaign over'])
            ->assertOk();

        $coupon->refresh();
        expect($coupon->is_active)->toBeFalse()->and($coupon->max_uses)->toBe(50)->and($coupon->expires_at)->not->toBeNull();
        expect(AdminAuditLog::where('action', 'coupon.deactivate')->count())->toBe(1);
    });

    it('still sets new values', function () {
        $coupon = limitedCoupon();
        $this->actingAsReauthed(couponStaff(AdminPermissions::SUPERADMIN))
            ->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['maxUses' => 10, 'expiresAt' => '2027-03-01', 'reason' => 'Extended'])
            ->assertOk();

        expect($coupon->refresh()->max_uses)->toBe(10)->and($coupon->expires_at->toDateString())->toBe('2027-03-01');
    });

    it('requires a reason and validates the cap', function () {
        $coupon = limitedCoupon();
        $admin = couponStaff(AdminPermissions::SUPERADMIN);
        $this->actingAsReauthed($admin)->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['maxUses' => null])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAsReauthed($admin)->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['maxUses' => 0, 'reason' => 'Nope nope'])
            ->assertStatus(422)->assertJsonValidationErrors('maxUses');
        expect($coupon->refresh()->max_uses)->toBe(50);
    });

    it('is closed to anonymous users, regular users and support', function () {
        $coupon = limitedCoupon();
        $payload = ['maxUses' => null, 'reason' => 'Open to everyone'];
        $url = "/api/v1/admin/payments/coupons/{$coupon->id}";

        $this->patchJson($url, $payload)->assertUnauthorized();
        $this->actingAsReauthed(User::factory()->create())->patchJson($url, $payload)->assertForbidden();
        $this->actingAsReauthed(couponStaff(AdminPermissions::SUPPORT))->patchJson($url, $payload)->assertForbidden();
        expect($coupon->refresh()->max_uses)->toBe(50);
    });
});

describe('coupon value bounds (F-2)', function () {
    $create = fn ($test, string $kind, int $value) => $test->actingAsReauthed(couponStaff(AdminPermissions::SUPERADMIN))
        ->postJson('/api/v1/admin/payments/coupons', ['code' => 'B' . $kind . $value, 'kind' => $kind, 'value' => $value, 'reason' => 'Bounds check']);

    it('accepts percent_off from 1 to 100 only', function () use ($create) {
        foreach ([1, 100] as $ok) {
            $create($this, 'percent_off', $ok)->assertCreated();
        }
        foreach ([0, 101, -5] as $bad) {
            $create($this, 'percent_off', $bad)->assertUnprocessable()->assertJsonValidationErrors('value');
        }
    });

    it('accepts a fixed_price of zero or more and rejects negatives', function () use ($create) {
        $create($this, 'fixed_price', 0)->assertCreated();
        $create($this, 'fixed_price', 7900)->assertCreated();
        $create($this, 'fixed_price', -1)->assertUnprocessable()->assertJsonValidationErrors('value');
    });

    it('never prices below zero even with a stored out-of-range value', function () {
        expect((new Coupon(['kind' => 'percent_off', 'value' => 101]))->apply(10000))->toBe(0)
            ->and((new Coupon(['kind' => 'percent_off', 'value' => -20]))->apply(10000))->toBe(10000)
            ->and((new Coupon(['kind' => 'fixed_price', 'value' => -500]))->apply(10000))->toBe(0)
            ->and((new Coupon(['kind' => 'percent_off', 'value' => 50]))->apply(9999))->toBe(5000);
    });
});
