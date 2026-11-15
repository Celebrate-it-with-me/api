<?php

namespace Database\Factories;

use App\Models\Events;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Events::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'allow_multiple_choices' => false,
            'allow_custom_request' => false,
            'is_default' => false,
        ];
    }
}
