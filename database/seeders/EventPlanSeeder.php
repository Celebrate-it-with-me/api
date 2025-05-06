<?php

namespace Database\Seeders;

use App\Models\EventPlan;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EventPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        EventPlan::query()->insert([
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'description' => 'Up to 100 guests. Core features only.',
                'has_gallery' => false,
                'has_music' => false,
                'has_custom_design' => false,
                'has_drag_editor' => false,
                'has_ai_assistant' => false,
                'has_invitations' => false,
                'has_sms' => false,
                'has_gift_registry' => false,
                'support_level' => 'basic',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'description' => 'Up to 200 guests. Includes gallery and music.',
                'has_gallery' => true,
                'has_music' => true,
                'has_custom_design' => true,
                'has_drag_editor' => false,
                'has_ai_assistant' => false,
                'has_invitations' => true,
                'has_sms' => false,
                'has_gift_registry' => true,
                'support_level' => 'standard',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Lux',
                'slug' => 'lux',
                'description' => 'Unlimited guests. All features unlocked.',
                'has_gallery' => true,
                'has_music' => true,
                'has_custom_design' => true,
                'has_drag_editor' => true,
                'has_ai_assistant' => true,
                'has_invitations' => true,
                'has_sms' => true,
                'has_gift_registry' => true,
                'support_level' => 'priority',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}
