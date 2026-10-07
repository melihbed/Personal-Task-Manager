<?php

namespace App\Observers;

use App\Models\CalendarSession;
use App\Models\GoogleEventImport;
use App\Services\GoogleCalendar\GoogleSyncDispatcher;

/** Pushes work sessions to Google Calendar when they are created, moved or removed. */
class CalendarSessionObserver
{
    public function saved(CalendarSession $session): void
    {
        GoogleSyncDispatcher::item($session->user_id, 'session', $session->id);
    }

    public function deleted(CalendarSession $session): void
    {
        GoogleEventImport::forget($session->user_id, 'session', $session->id);
        GoogleSyncDispatcher::item($session->user_id, 'session', $session->id);
    }
}
