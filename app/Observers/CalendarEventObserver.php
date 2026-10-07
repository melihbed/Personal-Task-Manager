<?php

namespace App\Observers;

use App\Models\CalendarEvent;
use App\Models\GoogleEventImport;

/** Forgets where a deleted event was copied from, so a Google event it came from can show in the preview again. */
class CalendarEventObserver
{
    public function deleted(CalendarEvent $event): void
    {
        GoogleEventImport::forget($event->user_id, 'event', $event->id);
    }
}
