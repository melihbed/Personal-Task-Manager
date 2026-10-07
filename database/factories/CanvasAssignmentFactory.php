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
    /** The assignment belongs to the same user as its course. */
    public function configure(): static
    {
        return $this->afterMaking(function (CanvasAssignment $assignment) {
            $assignment->user_id ??= $assignment->course?->user_id ?? CanvasCourse::find($assignment->canvas_course_id)?->user_id;
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
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
