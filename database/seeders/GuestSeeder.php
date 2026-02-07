<?php

namespace Database\Seeders;

use App\Models\Events;
use App\Models\Guest;
use App\Models\Menu;
use Illuminate\Database\Seeder;

class GuestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $events = Events::all();

        if ($events->isEmpty()) {
            $events = Events::factory()->count(3)->create();
        }

        $menus = Menu::all();

        foreach ($events as $event) {
            $mainGuests = Guest::factory()
                ->count(rand(8, 15))
                ->mainGuest()
                ->forEvent($event)
                ->state(function () use ($menus) {
                    $states = ['attending', 'not-attending', 'pending'];
                    $randomState = $states[array_rand($states)];

                    $guestData = [];

                    // Asignar estado
                    switch ($randomState) {
                        case 'attending':
                            $guestData = [
                                'rsvp_status' => 'attending',
                                'rsvp_status_date' => fake()->dateTimeBetween('-15 days', 'now')
                            ];
                            break;
                        case 'not-attending':
                            $guestData = [
                                'rsvp_status' => 'not-attending',
                                'rsvp_status_date' => fake()->dateTimeBetween('-15 days', 'now')
                            ];
                            break;
                        default:
                            $guestData = [
                                'rsvp_status' => 'pending',
                                'rsvp_status_date' => null
                            ];
                    }

                    if ($menus->isNotEmpty() && rand(1, 100) <= 70) {
                        $guestData['assigned_menu_id'] = $menus->random()->id;
                    }

                    return $guestData;
                })
                ->create();

            $guestsWithCompanions = $mainGuests->random(rand(3, 7));

            foreach ($guestsWithCompanions as $mainGuest) {
                $companionCount = rand(1, 3);

                for ($i = 0; $i < $companionCount; $i++) {
                    Guest::factory()
                        ->companion($mainGuest)
                        ->forEvent($event)
                        ->state([
                            'rsvp_status' => $mainGuest->rsvp_status,
                            'rsvp_status_date' => $mainGuest->rsvp_status_date,
                            'assigned_menu_id' => $mainGuest->assigned_menu_id,
                            'is_vip' => false,
                        ])
                        ->create();
                }
            }

            Guest::factory()
                ->count(rand(1, 3))
                ->mainGuest()
                ->vip()
                ->attending()
                ->forEvent($event)
                ->state(function () use ($menus) {
                    $guestData = [];
                    if ($menus->isNotEmpty()) {
                        $guestData['assigned_menu_id'] = $menus->random()->id;
                    }
                    return $guestData;
                })
                ->create();
        }

        $this->command->info('Guests seeded successfully!');
        $this->command->info('Total guests created: ' . Guest::count());
        $this->command->info('Main guests: ' . Guest::whereNull('parent_id')->count());
        $this->command->info('Companions: ' . Guest::whereNotNull('parent_id')->count());

        foreach ($events as $event) {
            $mainCount = Guest::where('event_id', $event->id)->whereNull('parent_id')->count();
            $companionCount = Guest::where('event_id', $event->id)->whereNotNull('parent_id')->count();
            $this->command->info("Event '{$event->name}': {$mainCount} main guests, {$companionCount} companions");
        }
    }
}
