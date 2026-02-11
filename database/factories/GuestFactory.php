<?php

namespace Database\Factories;

use App\Models\Events;
use App\Models\Guest;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Events::factory(),
            'parent_id' => null,
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'gender' => $this->faker->randomElement(['male', 'female', 'other']),
            'rsvp_status' => $this->faker->randomElement(['pending', 'attending', 'not-attending']),
            'rsvp_status_date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'assigned_menu_id' => null,
            'code' => strtoupper($this->faker->unique()->lexify('??????')),
            'notes' => $this->faker->optional(0.3)->sentence(),
            'is_vip' => $this->faker->boolean(20), // 20% chance de ser VIP
            'tags' => $this->faker->optional(0.4)->randomElements(
                ['vip', 'family', 'colleague', 'friend', 'plus-one', 'special-diet'],
                $this->faker->numberBetween(1, 3)
            ),
        ];
    }

    /**
     * Indicate that the guest is a companion (has parent_id).
     */
    public function companion(Guest $mainGuest = null): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $mainGuest?->id ?? Guest::factory(),
            'code' => null,
            'email' => $this->faker->optional(0.6)->safeEmail(),
        ]);
    }

    /**
     * Indicate that the guest is a main guest (no parent_id).
     */
    public function mainGuest(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => null,
            'code' => strtoupper($this->faker->unique()->lexify('??????')),
        ]);
    }

    /**
     * Indicate that the guest is VIP.
     */
    public function vip(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_vip' => true,
        ]);
    }

    /**
     * Indicate that the guest has confirmed attendance.
     */
    public function attending(): static
    {
        return $this->state(fn (array $attributes) => [
            'rsvp_status' => 'attending',
            'rsvp_status_date' => $this->faker->dateTimeBetween('-15 days', 'now'),
        ]);
    }

    /**
     * Indicate that the guest has declined attendance.
     */
    public function notAttending(): static
    {
        return $this->state(fn (array $attributes) => [
            'rsvp_status' => 'not-attending',
            'rsvp_status_date' => $this->faker->dateTimeBetween('-15 days', 'now'),
        ]);
    }

    /**
     * Indicate that the guest hasn't responded yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'rsvp_status' => 'pending',
            'rsvp_status_date' => null,
        ]);
    }

    /**
     * Create guest for specific event.
     */
    public function forEvent(Events $event): static
    {
        return $this->state(fn (array $attributes) => [
            'event_id' => $event->id,
        ]);
    }

    /**
     * Create guest with assigned menu.
     */
    public function withMenu(Menu $menu = null): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_menu_id' => $menu?->id ?? Menu::factory(),
        ]);
    }
}
