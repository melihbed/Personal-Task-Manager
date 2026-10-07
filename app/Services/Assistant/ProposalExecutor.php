<?php

namespace App\Services\Assistant;

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\CalendarSession;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Carries out a proposal the user approved. Everything is checked again, because the planner may have changed
 * since the assistant suggested it. What happened, or why it could not, is written to the action log.
 */
class ProposalExecutor
{
    public function __construct(private readonly ActionLog $log) {}

    /**
     * @param  array<string, mixed>  $proposal
     * @return string what was done, for the user
     *
     * @throws ProposalFailed
     */
    public function execute(User $user, array $proposal, ?AssistantMessage $message = null): string
    {
        $arguments = $proposal['args'] ?? [];
        $timezone = (string) ($proposal['timezone'] ?? 'UTC');

        try {
            $outcome = match ($proposal['type'] ?? '') {
                'create_task' => $this->createTask($user, $arguments, $timezone),
                'plan_session' => $this->planSession($user, $arguments, $timezone),
                'complete_task' => $this->completeTask($user, $arguments, $timezone),
                'update_task' => $this->updateTask($user, $arguments, $timezone),
                'delete_task' => $this->deleteTask($user, $arguments, $timezone),
                'create_routine' => $this->createRoutine($user, $arguments, $timezone),
                'update_routine' => $this->updateRoutine($user, $arguments),
                'delete_routine' => $this->deleteRoutine($user, $arguments),
                default => throw new ProposalFailed('This kind of change is not supported.'),
            };
        } catch (ProposalFailed $exception) {
            $this->log->record($user, $message, $proposal, AssistantAction::FAILED, $exception->getMessage());

            throw $exception;
        }

        $this->log->record($user, $message, $proposal, AssistantAction::APPLIED, $outcome['result'], $outcome['subject']);

        return $outcome['result'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function createTask(User $user, array $arguments, string $timezone): array
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

        return $this->outcome("Added the task “{$task->title}”.", 'task', $task->id, $task->title, $this->log->diff([], $this->log->taskFields($task, $timezone)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function planSession(User $user, array $arguments, string $timezone): array
    {
        $task = $this->openTask($user, $arguments['task_id'] ?? null);
        $start = CarbonImmutable::parse($arguments['starts_at'])->utc();
        $end = CarbonImmutable::parse($arguments['ends_at'])->utc();
        $session = null;

        DB::transaction(function () use ($user, $task, $start, $end, &$session) {
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

        $when = $start->setTimezone($timezone)->format('D M j, g:i').'–'.$end->setTimezone($timezone)->format('g:i A');

        return $this->outcome("Planned time for “{$task->title}”.", 'session', $session->id, $task->title, [['label' => 'Work session', 'from' => null, 'to' => $when]]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function completeTask(User $user, array $arguments, string $timezone): array
    {
        $task = $this->openTask($user, $arguments['task_id'] ?? null);
        $before = $this->log->taskFields($task, $timezone);
        $task->completed_at = now();
        $task->save();

        return $this->outcome("Marked “{$task->title}” as done.", 'task', $task->id, $task->title, $this->log->diff($before, $this->log->taskFields($task, $timezone)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function updateTask(User $user, array $arguments, string $timezone): array
    {
        $task = $this->anyTask($user, $arguments['task_id'] ?? null);
        $before = $this->log->taskFields($task, $timezone);
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

        return $this->outcome("Updated “{$task->title}”.", 'task', $task->id, $task->title, $this->log->diff($before, $this->log->taskFields($task, $timezone)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function deleteTask(User $user, array $arguments, string $timezone): array
    {
        $task = $this->anyTask($user, $arguments['task_id'] ?? null);
        $before = $this->log->taskFields($task, $timezone);
        $task->delete();

        return $this->outcome("Deleted the task “{$task->title}”.", 'task', $task->id, $task->title, $this->log->diff($before, []));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function createRoutine(User $user, array $arguments, string $timezone): array
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

        return $this->outcome("Added the routine “{$routine->title}”.", 'routine', $routine->id, $routine->title, $this->log->diff([], $this->log->routineFields($routine)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function updateRoutine(User $user, array $arguments): array
    {
        $routine = $this->routine($user, $arguments['routine_id'] ?? null);
        $before = $this->log->routineFields($routine);
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

        return $this->outcome("Updated the routine “{$routine->title}”.", 'routine', $routine->id, $routine->title, $this->log->diff($before, $this->log->routineFields($routine->refresh())));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function deleteRoutine(User $user, array $arguments): array
    {
        $routine = $this->routine($user, $arguments['routine_id'] ?? null);
        $before = $this->log->routineFields($routine);
        $routine->delete();

        return $this->outcome("Deleted the routine “{$routine->title}”.", 'routine', $routine->id, $routine->title, $this->log->diff($before, []));
    }

    /**
     * @param  list<array{label: string, from: string|null, to: string|null}>  $changes
     * @return array{result: string, subject: array<string, mixed>}
     */
    private function outcome(string $result, string $type, int $id, string $title, array $changes): array
    {
        return ['result' => $result, 'subject' => ['type' => $type, 'id' => $id, 'title' => $title, 'changes' => $changes]];
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
        $task = $this->anyTask($user, $id);

        if ($task->completed_at !== null) {
            throw new ProposalFailed('That task is already done.');
        }

        return $task;
    }
}
