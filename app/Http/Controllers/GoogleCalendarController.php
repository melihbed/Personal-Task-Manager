<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGoogleCalendarRequest;
use App\Models\GoogleAccount;
use App\Services\GoogleCalendar\GoogleApiException;
use App\Services\GoogleCalendar\GoogleCalendarBranding;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleReconnectRequired;
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
                'import_calendar_ids' => $account->import_calendar_ids ?? [],
            ] : null,
            // How many Google events are already in the planner, so the page can say what moving over has done.
            'importedCount' => $account ? $user->googleEventImports()->where('kind', '!=', 'hidden')->count() : 0,
            'hiddenEvents' => $account ? $user->googleEventImports()->where('kind', 'hidden')->orderBy('id')->get(['id', 'label'])->map(fn ($hidden) => ['id' => $hidden->id, 'label' => $hidden->label ?? '(No title)'])->values()->all() : [],
            // Listing calendars calls Google, so it loads after the page appears.
            'calendars' => $account && ! $account->needs_reconnect ? Inertia::defer(fn () => $this->calendars($account)) : [],
        ]);
    }

    /** Chooses which calendars are previewed on the planner calendar. */
    public function update(UpdateGoogleCalendarRequest $request): RedirectResponse
    {
        $account = $request->user()->googleAccount;

        abort_if($account === null || $account->needs_reconnect, 404);

        $calendars = collect($this->calendars($account))->keyBy('id');

        if ($calendars->isEmpty()) {
            throw ValidationException::withMessages(['import_calendar_ids' => 'Could not load your Google calendars. Please try again.']);
        }

        // "primary" is Google's alias for the user's main calendar; save the real id.
        $resolve = fn (string $id): string => $id === 'primary' ? ($calendars->firstWhere('primary', true)['id'] ?? $id) : $id;
        $ids = array_values(array_unique(array_map($resolve, $request->validated('import_calendar_ids'))));

        if (collect($ids)->contains(fn (string $id) => ! $calendars->has($id))) {
            throw ValidationException::withMessages(['import_calendar_ids' => 'Choose calendars from your own list.']);
        }

        $account->update(['import_calendar_ids' => $ids]);

        return back()->with('status', 'Saved.');
    }

    /** Disconnects: stops Google's access and forgets the tokens. Events already copied into the planner stay. */
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
