<?php

namespace Tests\Feature;

use App\Models\CalendarSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarSessionTest extends TestCase
{
    use RefreshDatabase;

    private function task(User $user): Task
    {
        return $user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);
    }

    private function payload(string $start = '09:00', string $end = '10:00', bool $allow = false): array
    {
        return ['starts_at' => "2026-10-05T{$start}:00Z", 'ends_at' => "2026-10-05T{$end}:00Z", 'allow_overlap' => $allow];
    }

    public function test_session_is_owned_by_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $this->actingAs($user)->from('/')->post("/tasks/{$task->id}/calendar-sessions", $this->payload())->assertRedirect('/');
        $this->assertDatabaseHas('calendar_sessions', ['user_id' => $user->id, 'task_id' => $task->id]);
    }

    public function test_other_users_tasks_cannot_be_scheduled_or_sessions_deleted(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $task = $this->task($owner);
        $session = new CalendarSession(['starts_at' => '2026-10-05T09:00:00Z', 'ends_at' => '2026-10-05T10:00:00Z']);
        $session->user()->associate($owner);
        $session->task()->associate($task);
        $session->save();
        $this->actingAs($other)->post("/tasks/{$task->id}/calendar-sessions", $this->payload())->assertNotFound();
        $this->delete("/calendar-sessions/{$session->id}")->assertNotFound();
        $this->assertDatabaseCount('calendar_sessions', 1);
    }

    public function test_overlap_requires_confirmation_and_adjacency_does_not(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $url = "/tasks/{$task->id}/calendar-sessions";
        $this->actingAs($user)->from('/')->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->post($url, $this->payload('09:30','10:30'))->assertSessionHasErrors('allow_overlap');
        $this->assertDatabaseCount('calendar_sessions', 1);
        $this->post($url, $this->payload('10:00','11:00'))->assertSessionHasNoErrors();
        $this->post($url, $this->payload('09:30','10:30', true))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('calendar_sessions', 3);
    }

    public function test_invalid_intervals_and_offsetless_times_are_rejected(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $url = "/tasks/{$task->id}/calendar-sessions";
        $this->actingAs($user)->from('/')->post($url, $this->payload('10:00','09:00'))->assertSessionHasErrors('ends_at');
        $this->post($url, ['starts_at' => '2026-10-05T09:00:00', 'ends_at' => '2026-10-05T10:00:00', 'allow_overlap' => false])->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('calendar_sessions', 0);
    }

    public function test_completed_tasks_cannot_receive_new_sessions(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $task->completed_at = now();
        $task->save();
        $this->actingAs($user)->from('/')->post("/tasks/{$task->id}/calendar-sessions", $this->payload())->assertSessionHasErrors('starts_at');
    }

    public function test_removing_a_session_keeps_the_task(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $this->actingAs($user)->from('/')->post("/tasks/{$task->id}/calendar-sessions", $this->payload());
        $session = CalendarSession::firstOrFail();
        $this->delete("/calendar-sessions/{$session->id}")->assertRedirect('/');
        $this->assertDatabaseCount('calendar_sessions', 0);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }
}
