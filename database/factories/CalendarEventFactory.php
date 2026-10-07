<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'all_day' => false,
            'starts_at' => '2026-10-07 19:00:00',
            'ends_at' => '2026-10-07 20:00:00',
        ];
    }

    /** An event that lasts whole days, from $first to $last (inclusive). */
    public function allDay(string $first = '2026-10-08', ?string $last = null): static
    {
        return $this->state(['all_day' => true, 'starts_at' => null, 'ends_at' => null, 'starts_on' => $first, 'ends_on' => $last ?? $first]);
    }
}
