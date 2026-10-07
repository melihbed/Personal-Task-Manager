<?php

use App\Jobs\SyncCanvasAccount;
use App\Models\CanvasAccount;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    CanvasAccount::where('needs_reconnect', false)->pluck('user_id')->each(fn (int $userId) => SyncCanvasAccount::dispatch($userId));
})->name('canvas-sync')->everyThirtyMinutes()->withoutOverlapping();
