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
                'description' => 'Up to 100 guests. Core features only.',
                'max_guests' => 100,
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
                'description' => 'Up to 200 guests. Includes gallery and music.',
                'max_guests' => 200,
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
            EventPlan::query()->updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );
        }
    }
}
