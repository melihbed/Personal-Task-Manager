<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\GoogleCalendar\GoogleReconnectRequired;
use App\Services\GoogleCalendar\GoogleSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Deletes every event this app created in the user's Google Calendar. */
class RemoveGoogleEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId) {}

    public function handle(GoogleSync $sync): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        try {
            $sync->removeAll($user);
        } catch (GoogleReconnectRequired $exception) {
            $this->fail($exception);
        }
    }
}
