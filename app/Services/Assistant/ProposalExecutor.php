<?php

namespace App\Services\Assistant;

use App\Models\CalendarSession;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Carries out a proposal the user approved. Everything is checked again, because the planner may have changed
 * since the assistant suggested it.
 */
class ProposalExecutor
{
    /**
     * @param  array<string, mixed>  $proposal
     * @return string what was done, for the user
     */
    public function execute(User $user, array $proposal): string
    {
        $arguments = $proposal['args'] ?? [];

        return match ($proposal['type'] ?? '') {
            'create_task' => $this->createTask($user, $arguments, (string) ($proposal['timezone'] ?? 'UTC')),
            'plan_session' => $this->planSession($user, $arguments),
            'complete_task' => $this->completeTask($user, $arguments),
            default => throw new ProposalFailed('This kind of change is not supported.'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function createTask(User $user, array $arguments, string $timezone): string
    {
        $task = new Task(['title' => $arguments['title'], 'priority' => $arguments['priority'] ?? 'normal', 'due_has_time' => true]);

        if (! empty($arguments['due_date'])) {
            if (! empty($arguments['due_time'])) {
                $task->due_at = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$arguments['due_date']} {$arguments['due_time']}", $timezone)->utc();
            } else {
                // A date-only deadline is stored as noon UTC on its date.
                $task->due_at = CarbonImmutable::parse("{$arguments['due_date']}T12:00:00Z");
                $task->due_has_time = false;
            }
        }

        $task->user()->associate($user);
        $task->save();

        return "Added the task “{$task->title}”.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function planSession(User $user, array $arguments): string
    {
        $task = $this->openTask($user, $arguments['task_id'] ?? null);
        $start = CarbonImmutable::parse($arguments['starts_at'])->utc();
        $end = CarbonImmutable::parse($arguments['ends_at'])->utc();

        DB::transaction(function () use ($user, $task, $start, $end) {
            // The same lock the calendar uses, so two saves cannot both slip past the overlap check.
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            if (CalendarSession::where('user_id', $user->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw new ProposalFailed('That time overlaps another work session. Plan it from the calendar to choose a different time.');
            }

            $session = new CalendarSession;
            $session->user()->associate($user);
            $session->task()->associate($task);
            $session->starts_at = $start;
            $session->ends_at = $end;
            $session->save();
        });

        return "Planned time for “{$task->title}”.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function completeTask(User $user, array $arguments): string
    {
        $task = $this->openTask($user, $arguments['task_id'] ?? null);
        $task->completed_at = now();
        $task->save();

        return "Marked “{$task->title}” as done.";
    }

    private function openTask(User $user, mixed $id): Task
    {
        $task = is_numeric($id) ? $user->tasks()->find((int) $id) : null;

        if ($task === null) {
            throw new ProposalFailed('That task no longer exists.');
        }

        if ($task->completed_at !== null) {
            throw new ProposalFailed('That task is already done.');
        }

        return $task;
    }
}
