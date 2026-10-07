<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGoogleCalendarRequest;
use App\Models\GoogleAccount;
use App\Services\GoogleCalendar\GoogleApiException;
use App\Services\GoogleCalendar\GoogleCalendarBranding;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleReconnectRequired;
use App\Services\GoogleCalendar\GoogleSyncDispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GoogleCalendarController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $account = $user->googleAccount;

        return Inertia::render('settings/google-calendar', [
            'configured' => GoogleAccount::isConfigured(),
            'state' => GoogleAccount::stateOf($account),
            'branding' => ['icon' => GoogleCalendarBranding::icon(), 'legal' => GoogleCalendarBranding::LEGAL],
            // Opened after a failed connection attempt, so the likely fixes are in view.
            'troubleshoot' => $request->boolean('troubleshoot'),
            'redirectUri' => config('services.google.redirect'),
            'account' => $account ? [
                'email' => $account->email,
                'needs_reconnect' => $account->needs_reconnect,
                'calendar_id' => $account->calendar_id,
                'calendar_name' => $account->calendar_name,
                'import_calendar_ids' => $account->import_calendar_ids ?? [],
                'push_sessions' => $account->push_sessions,
                'push_routines' => $account->push_routines,
                'push_deadlines' => $account->push_deadlines,
            ] : null,
            'hiddenEvents' => $account ? $user->googleEventImports()->where('kind', 'hidden')->orderBy('id')->get(['id', 'label'])->map(fn ($hidden) => ['id' => $hidden->id, 'label' => $hidden->label ?? '(No title)'])->values()->all() : [],
            'pushedEvents' => $account ? $user->googleEventLinks()->where('kind', '!=', 'occurrence')->count() : 0,
            // Listing calendars calls Google, so it loads after the page appears.
            'calendars' => $account && ! $account->needs_reconnect ? Inertia::defer(fn () => $this->calendars($account)) : [],
        ]);
    }

    /**
     * Saves the settings, then syncs whatever they change: everything when the target calendar changes,
     * otherwise just the kinds of item whose switch was flipped.
     */
    public function update(UpdateGoogleCalendarRequest $request): RedirectResponse
    {
        $user = $request->user();
        $account = $user->googleAccount;

        abort_if($account === null || $account->needs_reconnect, 404);

        $validated = $request->validated();
        $calendars = collect($this->calendars($account))->keyBy('id');

        if ($calendars->isEmpty()) {
            throw ValidationException::withMessages(['calendar_id' => 'Could not load your Google calendars. Please try again.']);
        }

        // "primary" is Google's alias for the user's main calendar; compare and save real ids.
        $resolve = fn (string $id): string => $id === 'primary' ? ($calendars->firstWhere('primary', true)['id'] ?? $id) : $id;
        $validated['calendar_id'] = $resolve($validated['calendar_id']);
        $validated['import_calendar_ids'] = array_values(array_unique(array_map($resolve, $validated['import_calendar_ids'])));

        $target = $calendars->get($validated['calendar_id']);

        if ($target === null || ! $target['writable']) {
            throw ValidationException::withMessages(['calendar_id' => 'Choose a calendar you can add events to.']);
        }

        if (collect($validated['import_calendar_ids'])->contains(fn (string $id) => ! $calendars->has($id))) {
            throw ValidationException::withMessages(['import_calendar_ids' => 'Choose calendars from your own list.']);
        }

        $toggles = ['push_sessions' => 'session', 'push_routines' => 'routine', 'push_deadlines' => 'deadline'];
        $calendarChanged = $resolve((string) $account->calendar_id) !== $validated['calendar_id'];
        $kinds = $calendarChanged
            ? array_values($toggles)
            : collect($toggles)->filter(fn (string $kind, string $field) => $account->{$field} !== $validated[$field])->values()->all();

        $account->update([
            // Keep the stored alias when the choice is the same calendar, so existing events are not moved needlessly.
            'calendar_id' => $calendarChanged ? $validated['calendar_id'] : $account->calendar_id,
            'calendar_name' => $target['name'],
            'import_calendar_ids' => $validated['import_calendar_ids'],
            'push_sessions' => $validated['push_sessions'],
            'push_routines' => $validated['push_routines'],
            'push_deadlines' => $validated['push_deadlines'],
        ]);

        if ($kinds !== []) {
            GoogleSyncDispatcher::all($user, $kinds);
        }

        return back()->with('status', 'Google Calendar settings saved.');
    }

    /** Disconnects: stops Google's access and forgets the tokens. Events already added to Google stay there. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $account = $user->googleAccount;

        if ($account !== null) {
            try {
                (new GoogleCalendarClient($account))->revoke();
            } catch (Throwable $exception) {
                report($exception);
            }

            $user->googleEventLinks()->delete();
            $account->delete();
        }

        return redirect()->route('google.show')->with('status', 'Google Calendar disconnected.');
    }

    /**
     * @return list<array{id: string, name: string, primary: bool, writable: bool, color: string|null}>
     */
    private function calendars(GoogleAccount $account): array
    {
        try {
            return Cache::remember("google-calendars:{$account->id}", 300, fn () => (new GoogleCalendarClient($account))->calendars());
        } catch (GoogleApiException|GoogleReconnectRequired|ConnectionException) {
            return [];
        }
    }
}
