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
        $this->actingAs(couponStaff(AdminPermissions::SUPERADMIN))
            ->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['maxUses' => null, 'reason' => 'Open to everyone'])
            ->assertOk();

        $coupon->refresh();
        expect($coupon->max_uses)->toBeNull()->and($coupon->expires_at)->not->toBeNull()->and($coupon->is_active)->toBeTrue();

        $log = AdminAuditLog::where('action', 'coupon.update')->firstOrFail();
        expect($log->before['max_uses'])->toBe(50)->and($log->after['max_uses'])->toBeNull();
    });

    it('removes the expiry with an explicit null and leaves the cap alone', function () {
        $coupon = limitedCoupon();
        $this->actingAs(couponStaff(AdminPermissions::SUPERADMIN))
            ->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['expiresAt' => null, 'reason' => 'No deadline'])
            ->assertOk();

        $coupon->refresh();
        expect($coupon->expires_at)->toBeNull()->and($coupon->max_uses)->toBe(50);

        $log = AdminAuditLog::where('action', 'coupon.update')->firstOrFail();
        expect($log->before['expires_at'])->not->toBeNull()->and($log->after['expires_at'])->toBeNull();
    });

    it('does not touch absent fields', function () {
        $coupon = limitedCoupon();
        $this->actingAs(couponStaff(AdminPermissions::SUPERADMIN))
            ->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['isActive' => false, 'reason' => 'Campaign over'])
            ->assertOk();

        $coupon->refresh();
        expect($coupon->is_active)->toBeFalse()->and($coupon->max_uses)->toBe(50)->and($coupon->expires_at)->not->toBeNull();
        expect(AdminAuditLog::where('action', 'coupon.deactivate')->count())->toBe(1);
    });

    it('still sets new values', function () {
        $coupon = limitedCoupon();
        $this->actingAs(couponStaff(AdminPermissions::SUPERADMIN))
            ->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['maxUses' => 10, 'expiresAt' => '2027-03-01', 'reason' => 'Extended'])
            ->assertOk();

        expect($coupon->refresh()->max_uses)->toBe(10)->and($coupon->expires_at->toDateString())->toBe('2027-03-01');
    });

    it('requires a reason and validates the cap', function () {
        $coupon = limitedCoupon();
        $admin = couponStaff(AdminPermissions::SUPERADMIN);
        $this->actingAs($admin)->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['maxUses' => null])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->patchJson("/api/v1/admin/payments/coupons/{$coupon->id}", ['maxUses' => 0, 'reason' => 'Nope nope'])
            ->assertStatus(422)->assertJsonValidationErrors('maxUses');
        expect($coupon->refresh()->max_uses)->toBe(50);
    });

    it('is closed to anonymous users, regular users and support', function () {
        $coupon = limitedCoupon();
        $payload = ['maxUses' => null, 'reason' => 'Open to everyone'];
        $url = "/api/v1/admin/payments/coupons/{$coupon->id}";

        $this->patchJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->patchJson($url, $payload)->assertForbidden();
        $this->actingAs(couponStaff(AdminPermissions::SUPPORT))->patchJson($url, $payload)->assertForbidden();
        expect($coupon->refresh()->max_uses)->toBe(50);
    });
});
