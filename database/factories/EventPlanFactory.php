<?php

namespace Database\Factories;

use App\Models\EventPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventPlan>
 */
class EventPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'max_guests' => $this->faker->randomElement([50, 100, 200, 500, 1000]),
            'slug' => $this->faker->unique()->slug(),
            'has_gallery' => $this->faker->boolean(80),
            'has_music' => $this->faker->boolean(70),
            'has_custom_design' => $this->faker->boolean(60),
            'has_drag_editor' => $this->faker->boolean(50),
            'has_ai_assistant' => $this->faker->boolean(40),
            'has_invitations' => $this->faker->boolean(90),
            'has_sms' => $this->faker->boolean(30),
            'has_gift_registry' => $this->faker->boolean(75),
            'support_level' => $this->faker->randomElement(['basic', 'standard', 'premium', 'enterprise']),
        ];
    }

    /**
     * Indicate that the plan is a basic plan.
     */
    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'support_level' => 'basic',
            'max_guest' => 50,
            'has_gallery' => false,
            'has_music' => false,
            'has_custom_design' => false,
            'has_drag_editor' => false,
            'has_ai_assistant' => false,
            'has_sms' => false,
        ]);
    }

    /**
     * Indicate that the plan is a premium plan.
     */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'support_level' => 'premium',
            'max_guest' => 1000,
            'has_gallery' => true,
            'has_music' => true,
            'has_custom_design' => true,
            'has_drag_editor' => true,
            'has_ai_assistant' => true,
            'has_invitations' => true,
            'has_sms' => true,
            'has_gift_registry' => true,
        ]);
    }
}
