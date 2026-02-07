<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SweetMemoriesImage>
 */
class SweetMemoriesImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => \App\Models\Events::factory(),
            'image_path' => 'images/event/1/memories-images/test.jpg',
            'image_name' => 'test.jpg',
            'image_original_name' => 'original_test.jpg',
            'image_size' => '1024',
            'thumbnail_path' => 'images/event/1/memories-thumbnails/thumbnail_test.jpg',
            'thumbnail_name' => 'thumbnail_test.jpg',
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'year' => (string) $this->faker->year(),
            'visible' => true,
            'order' => 0,
        ];
    }
}
