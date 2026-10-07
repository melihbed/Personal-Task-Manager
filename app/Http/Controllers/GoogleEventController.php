<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteGoogleEventRequest;
use App\Http\Requests\UpdateGoogleEventRequest;
use App\Models\GoogleAccount;
use App\Services\GoogleCalendar\EventActionFailed;
use App\Services\GoogleCalendar\GoogleEventManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GoogleEventController extends Controller
{
    /**
     * Change an event's title and time in Google Calendar.
     */
    public function update(UpdateGoogleEventRequest $request, GoogleEventManager $events): RedirectResponse
    {
        $data = $request->validated();
        $account = $this->account($request, $data['calendar_id']);

        try {
            $events->update($account, $data['calendar_id'], $data['event_id'], $data['scope'], $data);
        } catch (EventActionFailed $exception) {
            throw ValidationException::withMessages(['event' => $exception->getMessage()]);
        }

        return back()->with('status', 'Saved to Google Calendar.');
    }

    /**
     * Delete an event from Google Calendar: just that event, or the whole series it belongs to.
     */
    public function destroy(DeleteGoogleEventRequest $request, GoogleEventManager $events): RedirectResponse
    {
        $data = $request->validated();
        $account = $this->account($request, $data['calendar_id']);

        try {
            $events->delete($account, $data['calendar_id'], $data['event_id'], $data['scope']);
        } catch (EventActionFailed $exception) {
            throw ValidationException::withMessages(['event' => $exception->getMessage()]);
        }

        return back()->with('status', $data['scope'] === 'series' ? 'Deleted the series from Google Calendar.' : 'Deleted from Google Calendar.');
    }

    /** The connected account, provided the calendar is one shown in the planner. */
    private function account(Request $request, string $calendarId): GoogleAccount
    {
        $account = $request->user()->googleAccount;

        abort_if($account === null || $account->needs_reconnect, 404);

        if (! in_array($calendarId, $account->import_calendar_ids ?? [], true)) {
            throw ValidationException::withMessages(['event' => 'That calendar is not one of the calendars shown in the planner.']);
        }

        return $account;
    }
}
