<?php

namespace App\Services\Assistant;

use App\Models\CalendarSession;
use App\Models\Routine;
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
            'update_task' => $this->updateTask($user, $arguments, (string) ($proposal['timezone'] ?? 'UTC')),
            'delete_task' => $this->deleteTask($user, $arguments),
            'create_routine' => $this->createRoutine($user, $arguments, (string) ($proposal['timezone'] ?? 'UTC')),
            'update_routine' => $this->updateRoutine($user, $arguments),
            'delete_routine' => $this->deleteRoutine($user, $arguments),
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

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function updateTask(User $user, array $arguments, string $timezone): string
    {
        $task = $this->anyTask($user, $arguments['task_id'] ?? null);
        $changes = $arguments['changes'] ?? [];

        foreach (['title', 'priority', 'notes'] as $field) {
            if (array_key_exists($field, $changes)) {
                $task->{$field} = $changes[$field] === '' && $field === 'notes' ? null : $changes[$field];
            }
        }

        if (array_key_exists('due_date', $changes)) {
            if ($changes['due_date'] === null) {
                $task->due_at = null;
                $task->due_has_time = true;
            } elseif (! empty($changes['due_time'])) {
                $task->due_at = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$changes['due_date']} {$changes['due_time']}", $timezone)->utc();
                $task->due_has_time = true;
            } else {
                // A date-only deadline is stored as noon UTC on its date.
                $task->due_at = CarbonImmutable::parse("{$changes['due_date']}T12:00:00Z");
                $task->due_has_time = false;
            }
        }

        $task->save();

        return "Updated “{$task->title}”.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function deleteTask(User $user, array $arguments): string
    {
        $task = $this->anyTask($user, $arguments['task_id'] ?? null);
        $task->delete();

        return "Deleted the task “{$task->title}”.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function createRoutine(User $user, array $arguments, string $timezone): string
    {
        $routine = new Routine([
            'title' => $arguments['title'],
            'days' => $arguments['days'],
            'start_time' => $arguments['start_time'].':00',
            'duration_minutes' => $arguments['minutes'],
            'timezone' => $timezone,
            'starts_on' => $arguments['starts_on'],
            'ends_on' => $arguments['ends_on'] ?? null,
        ]);
        $routine->user()->associate($user);
        $routine->save();

        return "Added the routine “{$routine->title}”.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function updateRoutine(User $user, array $arguments): string
    {
        $routine = $this->routine($user, $arguments['routine_id'] ?? null);
        $changes = $arguments['changes'] ?? [];
        $reschedules = array_intersect_key($changes, array_flip(['days', 'start_time', 'minutes'])) !== [];

        // A new schedule puts moved days back, as editing a routine in the planner does. Skipped and completed days stay.
        if ($reschedules) {
            $routine->occurrences()->whereNotNull('starts_at')->update(['starts_at' => null, 'ends_at' => null]);
        }

        foreach (['title', 'days'] as $field) {
            if (array_key_exists($field, $changes)) {
                $routine->{$field} = $changes[$field];
            }
        }

        if (array_key_exists('start_time', $changes)) {
            $routine->start_time = $changes['start_time'].':00';
        }

        if (array_key_exists('minutes', $changes)) {
            $routine->duration_minutes = $changes['minutes'];
        }

        if (array_key_exists('ends_on', $changes)) {
            $routine->ends_on = $changes['ends_on'];
        }

        $routine->save();

        return "Updated the routine “{$routine->title}”.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function deleteRoutine(User $user, array $arguments): string
    {
        $routine = $this->routine($user, $arguments['routine_id'] ?? null);
        $routine->delete();

        return "Deleted the routine “{$routine->title}”.";
    }

    private function anyTask(User $user, mixed $id): Task
    {
        return (is_numeric($id) ? $user->tasks()->find((int) $id) : null) ?? throw new ProposalFailed('That task no longer exists.');
    }

    private function routine(User $user, mixed $id): Routine
    {
        return (is_numeric($id) ? $user->routines()->find((int) $id) : null) ?? throw new ProposalFailed('That routine no longer exists.');
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
