<?php

namespace Database\Factories;

use App\Models\events;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<events>
 */
class EventActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => 1,
            'type' => $this->faker->randomElement(['guest_confirmed', 'photo_uploaded', 'music_added']),
            'actor_type' => 'App\\Models\\User',
            'actor_id' => 1,
            'target_type' => 'App\\Models\\Guest',
            'target_id' => 1,
            'payload' => [
                'name' => $this->faker->name,
                'info' => $this->faker->sentence,
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
