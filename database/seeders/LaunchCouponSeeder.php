<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

/**
 * The launch offer: list price 99 USD, 79 USD with this founder code (20 USD off 9900 cents).
 *
 * Idempotent and safe in production: it only creates the coupon when the code does not exist. If the admin has
 * changed it (limit, expiry, deactivated) a second run leaves it exactly as it is.
 *
 *   php artisan db:seed --class='Database\Seeders\LaunchCouponSeeder' --force
 */
class LaunchCouponSeeder extends Seeder
{
    public const CODE = 'LANZAMIENTO79';

    public function run(): void
    {
        Coupon::query()->firstOrCreate(['code' => self::CODE], [
            'kind' => 'fixed_price',
            'value' => 7900,
            'is_founder' => true,
            'max_uses' => 100,
            'expires_at' => null,
            'is_active' => true,
            'notes' => 'Launch offer: full event for 79 USD (list price 99). Edit the limit in the admin.',
        ]);
    }
}
