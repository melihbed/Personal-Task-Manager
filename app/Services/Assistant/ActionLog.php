<?php

namespace App\Services\Assistant;

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Keeps a record of what happened to every change the assistant suggested, so the user can always see what changed,
 * when, and from what to what. Values are stored as the user would read them (a deadline as "Fri Oct 9, 5:00 PM").
 */
class ActionLog
{
    /**
     * @param  array<string, mixed>  $proposal
     * @param  array{type: string, id: int|null, title: string, changes: list<array{label: string, from: string|null, to: string|null}>}|null  $subject
     */
    public function record(User $user, ?AssistantMessage $message, array $proposal, string $status, ?string $result = null, ?array $subject = null): AssistantAction
    {
        $action = new AssistantAction([
            'type' => $proposal['type'],
            'status' => $status,
            'summary' => $proposal['summary'],
            'result' => $result,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'subject_title' => $subject['title'] ?? null,
            'changes' => $subject['changes'] ?? null,
        ]);
        $action->user()->associate($user);

        if ($message !== null) {
            $action->message()->associate($message);
        }

        $action->save();

        return $action;
    }

    /**
     * What a task looks like, field by field, as the user reads it.
     *
     * @return array<string, string|null>
     */
    public function taskFields(Task $task, string $timezone): array
    {
        return [
            'Title' => $task->title,
            'Deadline' => $task->due_at === null ? null : ($task->due_has_time ? $task->due_at->setTimezone($timezone)->format('D M j, g:i A') : $task->due_at->format('D M j').' (date only)'),
            'Priority' => $task->priority,
            'Notes' => filled($task->notes) ? $task->notes : null,
            'Status' => $task->completed_at === null ? 'Open' : 'Done',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function routineFields(Routine $routine): array
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return [
            'Name' => $routine->title,
            'Days' => implode(', ', array_map(fn (int $day) => $names[$day], $routine->days)),
            'Starts at' => CarbonImmutable::createFromFormat('H:i:s', $routine->start_time)->format('g:i A'),
            'Length' => "{$routine->duration_minutes} minutes",
            'From' => $routine->starts_on->format('D M j, Y'),
            'Until' => $routine->ends_on?->format('D M j, Y'),
        ];
    }

    /**
     * The fields that differ. Nothing before means it was created; nothing after means it was deleted.
     *
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     * @return list<array{label: string, from: string|null, to: string|null}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $label) {
            $from = $before[$label] ?? null;
            $to = $after[$label] ?? null;

            if ($from !== $to) {
                $changes[] = ['label' => $label, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }
}
