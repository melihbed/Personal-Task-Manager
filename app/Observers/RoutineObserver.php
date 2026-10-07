<?php

namespace App\Observers;

use App\Models\GoogleEventImport;
use App\Models\Routine;

/** Forgets where a deleted routine was copied from, so a Google event it came from can show in the preview again. */
class RoutineObserver
{
    public function deleted(Routine $routine): void
    {
        GoogleEventImport::forget($routine->user_id, 'routine', $routine->id);
    }
}
