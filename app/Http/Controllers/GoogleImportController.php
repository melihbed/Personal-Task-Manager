<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportGoogleEventRequest;
use App\Services\GoogleCalendar\GoogleApiException;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleEventImporter;
use App\Services\GoogleCalendar\GoogleReconnectRequired;
use App\Services\GoogleCalendar\ImportNotPossible;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class GoogleImportController extends Controller
{
    /**
     * Copy one Google event into the planner as an event, a task, a work session or a routine.
     */
    public function store(ImportGoogleEventRequest $request, GoogleEventImporter $importer): RedirectResponse
    {
        $user = $request->user();
        $account = $user->googleAccount;

        abort_if($account === null || $account->needs_reconnect, 404);

        $data = $request->validated();
        $calendarId = $data['calendar_id'];

        if (! in_array($calendarId, $account->import_calendar_ids ?? [], true)) {
            throw ValidationException::withMessages(['type' => 'That calendar is not one of the calendars shown in the planner.']);
        }

        $client = new GoogleCalendarClient($account);
        $event = $this->read($client, $calendarId, $data['event_id']);

        if (($event['status'] ?? '') === 'cancelled') {
            throw ValidationException::withMessages(['type' => 'That event is no longer in Google Calendar.']);
        }

        // A routine copies the whole series, so the series is what is remembered.
        $isRoutine = $data['type'] === 'routine';
        $key = $isRoutine ? ($event['recurringEventId'] ?? null) : $event['id'];

        if ($key === null) {
            throw ValidationException::withMessages(['type' => 'This event does not repeat, so it cannot become a routine.']);
        }

        if ($user->googleEventImports()->where('google_calendar_id', $calendarId)->where('google_event_id', $key)->exists()) {
            throw ValidationException::withMessages(['type' => 'This event is already in your planner.']);
        }

        $responsibilityId = $data['responsibility_id'] ?? null;

        try {
            if ($data['type'] === 'event') {
                $importer->asEvent($user, $calendarId, $event, $responsibilityId);
            } elseif ($data['type'] === 'task') {
                $importer->asTask($user, $calendarId, $event, $responsibilityId);
            } elseif ($data['type'] === 'session') {
                $importer->asSession($user, $calendarId, $event, $responsibilityId);
            } else {
                $importer->asRoutine($user, $calendarId, $this->read($client, $calendarId, $key), $responsibilityId, $data['timezone']);
            }
        } catch (ImportNotPossible $exception) {
            throw ValidationException::withMessages(['type' => $exception->getMessage()]);
        }

        $label = ['event' => 'an event', 'task' => 'a task', 'session' => 'a work session', 'routine' => 'a routine'][$data['type']];
        $title = trim((string) ($event['summary'] ?? '')) ?: '(No title)';

        return back()->with('status', "Added “{$title}” as {$label}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function read(GoogleCalendarClient $client, string $calendarId, string $eventId): array
    {
        try {
            return $client->event($calendarId, $eventId);
        } catch (GoogleApiException $exception) {
            throw ValidationException::withMessages(['type' => $exception->isGone() ? 'That event is no longer in Google Calendar.' : 'Could not read that event from Google. Please try again.']);
        } catch (GoogleReconnectRequired|ConnectionException) {
            throw ValidationException::withMessages(['type' => 'Could not reach Google Calendar. Please try again.']);
        }
    }
}
