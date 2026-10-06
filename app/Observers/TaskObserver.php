<?php

namespace App\Observers;

use App\Models\Task;
use App\Services\GoogleCalendar\GoogleSyncDispatcher;

/**
 * Pushes a task's deadline to Google Calendar, and cleans up after the task and its sessions are deleted.
 */
class TaskObserver
{
    /**
     * Session ids of tasks being deleted, since the database removes them with the task. Static because
     * Laravel creates a new observer instance for each model event.
     *
     * @var array<int, list<int>>
     */
    private static array $sessionIds = [];

    public function saved(Task $task): void
    {
        $changed = $task->wasRecentlyCreated || $task->wasChanged(['title', 'due_at', 'due_has_time', 'completed_at', 'responsibility_id']);

        if ($changed && ($task->due_at !== null || ! $task->wasRecentlyCreated)) {
            GoogleSyncDispatcher::item($task->user_id, 'deadline', $task->id);
        }
    }

    public function deleting(Task $task): void
    {
        self::$sessionIds[$task->id] = $task->calendarSessions()->pluck('id')->all();
    }

    public function deleted(Task $task): void
    {
        GoogleSyncDispatcher::item($task->user_id, 'deadline', $task->id);

        foreach (self::$sessionIds[$task->id] ?? [] as $sessionId) {
            GoogleSyncDispatcher::item($task->user_id, 'session', $sessionId);
        }

        unset(self::$sessionIds[$task->id]);
    }
}
