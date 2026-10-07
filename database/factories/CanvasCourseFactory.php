<?php

namespace Database\Factories;

use App\Models\CanvasCourse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanvasCourse>
 */
class CanvasCourseFactory extends Factory
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
            'canvas_id' => fake()->unique()->numberBetween(1000, 99999),
            'name' => fake()->words(3, true),
            'course_code' => strtoupper(fake()->lexify('???')).' '.fake()->numberBetween(100, 499),
            'term' => 'Fall 2026',
            'tracked' => true,
        ];
    }
}
