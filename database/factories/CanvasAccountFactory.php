<?php

namespace Database\Factories;

use App\Models\CanvasAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanvasAccount>
 */
class CanvasAccountFactory extends Factory
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
            'base_url' => 'https://njit.instructure.com',
            'access_token' => 'canvas-token',
            'canvas_user_name' => fake()->name(),
        ];
    }

    public function needingReconnect(): static
    {
        return $this->state(['needs_reconnect' => true]);
    }
}
