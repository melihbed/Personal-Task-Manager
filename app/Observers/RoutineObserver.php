<?php

namespace App\Observers;

use App\Models\GoogleEventImport;
use App\Models\Routine;
use App\Services\GoogleCalendar\GoogleSyncDispatcher;

/** Pushes a routine to Google Calendar as one recurring event. */
class RoutineObserver
{
    public function saved(Routine $routine): void
    {
        GoogleSyncDispatcher::item($routine->user_id, 'routine', $routine->id);
    }

    public function deleted(Routine $routine): void
    {
        GoogleEventImport::forget($routine->user_id, 'routine', $routine->id);
        GoogleSyncDispatcher::item($routine->user_id, 'routine', $routine->id);
    }
}
