<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Canvas\CanvasSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Brings one user's Canvas courses, assignments and tasks up to date. */
class SyncCanvasAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $userId) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("canvas-sync:{$this->userId}"))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(CanvasSync $sync): void
    {
        $user = User::find($this->userId);

        if ($user !== null) {
            $sync->run($user);
        }
    }
}
