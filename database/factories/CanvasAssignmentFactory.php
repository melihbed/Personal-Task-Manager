<?php

namespace Database\Factories;

use App\Models\CanvasAssignment;
use App\Models\CanvasCourse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanvasAssignment>
 */
class CanvasAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (array $attributes) => CanvasCourse::find($attributes['canvas_course_id'])->user_id,
            'canvas_course_id' => CanvasCourse::factory(),
            'canvas_id' => fake()->unique()->numberBetween(1000, 999999),
            'name' => fake()->words(3, true),
            'kind' => 'assignment',
            'due_at' => now()->addDays(3),
            'points_possible' => 10,
            'html_url' => 'https://njit.instructure.com/courses/1/assignments/1',
        ];
    }
}
