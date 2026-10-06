<?php

namespace App\Http\Controllers;

use App\Jobs\RemoveGoogleEvents;
use App\Services\GoogleCalendar\GoogleSyncDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleSyncController extends Controller
{
    /** Sync now: send every planner item to Google again. Items already up to date are skipped. */
    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->googleAccount === null, 404);

        GoogleSyncDispatcher::all($request->user(), ['session', 'deadline', 'routine']);

        return back()->with('status', 'Syncing with Google Calendar in the background.');
    }

    /** Removes every event this app added to Google Calendar. */
    public function destroy(Request $request): RedirectResponse
    {
        abort_if($request->user()->googleAccount === null, 404);

        RemoveGoogleEvents::dispatch($request->user()->id);

        return back()->with('status', 'Removing planner events from Google Calendar in the background.');
    }
}
