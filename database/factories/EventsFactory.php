<?php

namespace Database\Factories;

use App\Models\EventPlan;
use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Events>
 */
class EventsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('now', '+6 months');
        $endDate = $this->faker->dateTimeBetween($startDate, '+1 year');

        return [
            'event_name' => $this->faker->sentence(3),
            'event_description' => $this->faker->text(200),
            'event_type_id' => EventType::factory(),
            'event_plan_id' => EventPlan::factory(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $this->faker->randomElement(['draft', 'published', 'archived']),
            // 3 words: the API accepts addresses up to 60 characters, and the default 6 words can go over.
            'custom_url_slug' => $this->faker->unique()->slug(3),
        ];
    }

    /**
     * Indicate that the event is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the event is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }
}
