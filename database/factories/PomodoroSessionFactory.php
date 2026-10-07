<?php

namespace Database\Factories;

use App\Models\PomodoroSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PomodoroSession>
 */
class PomodoroSessionFactory extends Factory
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
            'kind' => PomodoroSession::FOCUS,
            'planned_seconds' => 3000,
            'started_at' => now(),
            'status' => 'running',
        ];
    }

    /** A focus round that ran to the end, finishing at $endedAt. */
    public function completed(?\DateTimeInterface $endedAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'started_at' => ($endedAt ? CarbonImmutable::instance($endedAt) : now())->subSeconds($attributes['planned_seconds']),
            'ended_at' => $endedAt ?? now(),
        ]);
    }
}
