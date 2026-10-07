<?php

namespace App\Http\Controllers;

use App\Http\Requests\HideGoogleEventRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GoogleHiddenEventController extends Controller
{
    /**
     * Hide a Google event, or a whole series, from the planner calendar. Nothing changes in Google.
     */
    public function store(HideGoogleEventRequest $request): RedirectResponse
    {
        $user = $request->user();
        $account = $user->googleAccount;

        abort_if($account === null || $account->needs_reconnect, 404);

        $data = $request->validated();

        if (! in_array($data['calendar_id'], $account->import_calendar_ids ?? [], true)) {
            throw ValidationException::withMessages(['event' => 'That calendar is not one of the calendars shown in the planner.']);
        }

        $series = $data['scope'] === 'series';
        $title = trim((string) ($data['title'] ?? '')) ?: '(No title)';

        $user->googleEventImports()->firstOrCreate(
            ['google_calendar_id' => $data['calendar_id'], 'google_event_id' => $series ? $data['recurring_event_id'] : $data['event_id']],
            ['kind' => 'hidden', 'item_id' => 0, 'label' => $series ? "{$title} (all events)" : $title],
        );

        return back()->with('status', $series ? 'Hid all events of the series from your planner.' : 'Hid the event from your planner.');
    }

    /** Show a hidden event on the planner calendar again. */
    public function destroy(Request $request, string $hidden): RedirectResponse
    {
        $request->user()->googleEventImports()->where('kind', 'hidden')->findOrFail($hidden)->delete();

        return back()->with('status', 'The event is shown in your planner again.');
    }
}
