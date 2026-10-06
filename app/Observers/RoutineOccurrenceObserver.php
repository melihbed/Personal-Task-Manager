<?php

namespace App\Observers;

use App\Models\RoutineOccurrence;
use App\Services\GoogleCalendar\GoogleSyncDispatcher;

/** A skipped or moved day changes the routine's Google event, so the whole routine is synced again. */
class RoutineOccurrenceObserver
{
    public function saved(RoutineOccurrence $occurrence): void
    {
        $this->syncRoutine($occurrence);
    }

    public function deleted(RoutineOccurrence $occurrence): void
    {
        $this->syncRoutine($occurrence);
    }

    private function syncRoutine(RoutineOccurrence $occurrence): void
    {
        $userId = $occurrence->routine()->value('user_id');

        if ($userId !== null) {
            GoogleSyncDispatcher::item($userId, 'routine', $occurrence->routine_id);
        }
    }
}
