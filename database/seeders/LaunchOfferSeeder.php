<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\LaunchOffer;
use Illuminate\Database\Seeder;

/**
 * The launch offer: 79 USD (list 99) for the first 100 paid events, shown without a code.
 *
 * Idempotent and safe in production: it only creates the offer when the plan has none. If the admin changed it (price,
 * slots, switched it off) a second run leaves it exactly as it is. Running it IS what turns the offer on.
 *
 *   php artisan db:seed --class='Database\Seeders\LaunchOfferSeeder' --force
 */
class LaunchOfferSeeder extends Seeder
{
    public const PRICE_CENTS = 7900;

    public const TOTAL_SLOTS = 100;

    public function run(): void
    {
        $plan = EventPlan::query()->where('slug', 'premium')->first();
        if (! $plan) {
            return;
        }

        $offer = LaunchOffer::query()->firstOrCreate(['event_plan_id' => $plan->id], [
            'is_active' => true, 'price_cents' => self::PRICE_CENTS, 'total_slots' => self::TOTAL_SLOTS, 'used_adjustment' => 0,
        ]);

        // The founder code shares the offer's slots (one cap of N for both). Only fills it when empty.
        Coupon::query()->where('code', LaunchCouponSeeder::CODE)->whereNull('launch_offer_id')->update(['launch_offer_id' => $offer->id]);
    }
}
