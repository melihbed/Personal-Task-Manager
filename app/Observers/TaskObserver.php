<?php

namespace App\Observers;

use App\Models\GoogleEventImport;
use App\Models\Task;

/** Forgets where a deleted task, and the sessions that went with it, were copied from. */
class TaskObserver
{
    /**
     * Session ids of tasks being deleted, since the database removes them with the task. Static because
     * Laravel creates a new observer instance for each model event.
     *
     * @var array<int, list<int>>
     */
    private static array $sessionIds = [];

    public function deleting(Task $task): void
    {
        self::$sessionIds[$task->id] = $task->calendarSessions()->pluck('id')->all();
    }

    public function deleted(Task $task): void
    {
        GoogleEventImport::forget($task->user_id, 'task', $task->id);

        foreach (self::$sessionIds[$task->id] ?? [] as $sessionId) {
            GoogleEventImport::forget($task->user_id, 'session', $sessionId);
        }

        unset(self::$sessionIds[$task->id]);
    }
}
