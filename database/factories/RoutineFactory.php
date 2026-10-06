<?php

namespace Database\Factories;

use App\Models\Routine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Routine>
 */
class RoutineFactory extends Factory
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
            'days' => [2, 4],
            'start_time' => '08:00:00',
            'duration_minutes' => 60,
            'timezone' => 'America/New_York',
            'starts_on' => '2026-10-01',
            'ends_on' => null,
        ];
    }
}
