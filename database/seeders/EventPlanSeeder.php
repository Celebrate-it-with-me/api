<?php

namespace Database\Seeders;

use App\Models\EventPlan;
use Illuminate\Database\Seeder;

class EventPlanSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'slug' => 'basic',
                'name' => 'Basic',
                'description' => 'Free: up to 30 guests, core features.',
                'max_guests' => 30,
                'has_gallery' => false,
                'has_music' => false,
                'has_custom_design' => false,
                'has_drag_editor' => false,
                'has_ai_assistant' => false,
                'has_invitations' => false,
                'has_sms' => false,
                'has_gift_registry' => false,
                'support_level' => 'basic',
            ],
            [
                'slug' => 'premium',
                'name' => 'Premium',
                'description' => 'Full event: up to 300 guests, every module. One-time payment.',
                'max_guests' => 300,
                'price_cents' => 9900,
                'currency' => 'USD',
                'has_gallery' => true,
                'has_music' => true,
                'has_custom_design' => true,
                'has_drag_editor' => false,
                'has_ai_assistant' => false,
                'has_invitations' => true,
                'has_sms' => false,
                'has_gift_registry' => true,
                'support_level' => 'standard',
            ],
            [
                'slug' => 'lux',
                'name' => 'Lux',
                'description' => 'Unlimited guests. All features unlocked.',
                'max_guests' => 0,
                'has_gallery' => true,
                'has_music' => true,
                'has_custom_design' => true,
                'has_drag_editor' => true,
                'has_ai_assistant' => true,
                'has_invitations' => true,
                'has_sms' => true,
                'has_gift_registry' => true,
                'support_level' => 'priority',
            ],
        ];
        
        foreach ($rows as $row) {
            // The price is edited by the admin: seeding again never overwrites it.
            $plan = EventPlan::query()->firstOrNew(['slug' => $row['slug']]);
            $price = $plan->exists ? [] : array_intersect_key($row, array_flip(['price_cents', 'currency']));
            $plan->fill(array_diff_key($row, array_flip(['price_cents', 'currency'])) + $price)->save();
        }
    }
}
