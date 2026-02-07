<?php

namespace Database\Seeders;

use App\Models\Guest;
use App\Models\GuestCompanion;
use Illuminate\Database\Seeder;

class GuestCompanionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $mainGuests = Guest::whereNull('parent_id')->get();

        if ($mainGuests->isEmpty()) {
            $this->command->warn('No main guests found. Please run GuestSeeder first.');
            return;
        }

        foreach ($mainGuests as $mainGuest) {
            if (rand(1, 100) <= 50) {
                $companionCount = rand(1, 2);

                GuestCompanion::factory()
                    ->count($companionCount)
                    ->state([
                        'main_guest_id' => $mainGuest->id,
                        'confirmed' => rand(1, 100) <= 60,
                        'confirmed_at' => function ($attributes) {
                            return $attributes['confirmed'] ? fake()->dateTimeBetween('-15 days', 'now') : null;
                        },
                    ])
                    ->create();
            }
        }

        $someGuests = $mainGuests->random(min(8, $mainGuests->count()));

        foreach ($someGuests as $guest) {
            GuestCompanion::factory()
                ->confirmed()
                ->withMealPreference()
                ->state(['main_guest_id' => $guest->id])
                ->create();
        }

        $this->command->info('Guest companions seeded successfully!');
        $this->command->info('Total companion records created: ' . GuestCompanion::count());
        $this->command->info('Confirmed companions: ' . GuestCompanion::where('confirmed', true)->count());
        $this->command->info('Companions with meal preferences: ' . GuestCompanion::whereNotNull('meal_preference')->count());

        $guestsWithCompanions = GuestCompanion::distinct('main_guest_id')->count();
        $this->command->info("Main guests with additional companions: {$guestsWithCompanions}");
    }
}
