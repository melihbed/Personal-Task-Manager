<?php

namespace Database\Factories;

use App\Models\AssistantAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssistantAction>
 */
class AssistantActionFactory extends Factory
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
            'type' => 'update_task',
            'status' => AssistantAction::APPLIED,
            'summary' => fake()->sentence(),
        ];
    }
}
