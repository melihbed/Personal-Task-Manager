<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\GoogleCalendar\GoogleReconnectRequired;
use App\Services\GoogleCalendar\GoogleSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Brings one planner item's Google event up to date. Runs in the background so saving never waits on Google. */
class SyncGoogleItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  'session'|'deadline'|'routine'  $kind
     */
    public function __construct(public int $userId, public string $kind, public int $itemId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 90, 300];
    }

    /**
     * One sync per item at a time, so two quick edits cannot create the same event twice.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("google-sync:{$this->userId}:{$this->kind}:{$this->itemId}"))->releaseAfter(15)->expireAfter(180)];
    }

    public function handle(GoogleSync $sync): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        try {
            $sync->sync($user, $this->kind, $this->itemId);
        } catch (GoogleReconnectRequired $exception) {
            // Retrying cannot help until the user connects again.
            $this->fail($exception);
        }
    }
}
