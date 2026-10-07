<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendar\GoogleMigration;
use App\Services\GoogleCalendar\ImportNotPossible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GoogleMigrationController extends Controller
{
    /**
     * Move the user's Google calendar into the app, then switch the Google preview off.
     */
    public function store(Request $request, GoogleMigration $migration): RedirectResponse
    {
        $validated = $request->validate(['timezone' => ['required', 'timezone']]);

        abort_if($request->user()->googleAccount === null, 404);

        // Reading a year of events and each repeating series can take a while.
        set_time_limit(300);

        try {
            $result = $migration->run($request->user(), $validated['timezone']);
        } catch (ImportNotPossible $exception) {
            throw ValidationException::withMessages(['migration' => $exception->getMessage()]);
        }

        return back()
            ->with('status', "Moved {$result['events']} ".($result['events'] === 1 ? 'event' : 'events')." and {$result['routines']} ".($result['routines'] === 1 ? 'routine' : 'routines').' into your planner. The Google preview is now off.')
            ->with('migrationNotes', $result['notes']);
    }
}
