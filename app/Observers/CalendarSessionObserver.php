<?php

namespace App\Observers;

use App\Models\CalendarSession;
use App\Models\GoogleEventImport;

/** Forgets where a deleted work session was copied from, so a Google event it came from can show in the preview again. */
class CalendarSessionObserver
{
    public function deleted(CalendarSession $session): void
    {
        GoogleEventImport::forget($session->user_id, 'session', $session->id);
    }
}
