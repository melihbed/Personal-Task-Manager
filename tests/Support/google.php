<?php

use App\Models\CalendarSession;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Google Calendar test helpers
|--------------------------------------------------------------------------
|
| Shared by the Google test files. Loaded from tests/Pest.php.
|
*/

/** A user with a connected Google account that pushes everything to the calendar "cal-1". */
function googleUser(array $account = []): User
{
    $user = User::factory()->create();
    $user->googleAccount()->create(array_merge([
        'email' => 'me@example.com',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addHour(),
        'calendar_id' => 'cal-1',
        'calendar_name' => 'Planner',
        'import_calendar_ids' => ['primary'],
    ], $account));

    return $user;
}

/**
 * Pretends to be Google. Created events get ids "evt-1", "evt-2" ...; $failures maps "METHOD fragment"
 * to a status code to return instead.
 *
 * @param  array<string, int>  $failures
 */
function fakeGoogle(array $failures = [], array $events = []): void
{
    $created = 0;

    Http::fake(function (Request $request) use (&$created, $failures, $events) {
        $url = $request->url();

        foreach ($failures as $key => $status) {
            [$method, $fragment] = explode(' ', $key, 2);

            if ($request->method() === $method && str_contains($url, $fragment)) {
                return Http::response(['error' => ['message' => 'failed']], $status);
            }
        }

        return match (true) {
            str_contains($url, 'oauth2.googleapis.com/token') => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
            str_contains($url, 'oauth2.googleapis.com/revoke') => Http::response([]),
            str_contains($url, '/users/me/calendarList') => Http::response(['items' => [
                ['id' => 'primary-id', 'summary' => 'Me', 'primary' => true, 'accessRole' => 'owner', 'backgroundColor' => '#112233'],
                ['id' => 'cal-1', 'summary' => 'Planner', 'accessRole' => 'owner', 'backgroundColor' => '#445566'],
                ['id' => 'holidays', 'summary' => 'Holidays', 'accessRole' => 'reader', 'backgroundColor' => '#778899'],
            ]]),
            $request->method() === 'POST' && str_ends_with($url, '/events') => Http::response(['id' => 'evt-'.++$created]),
            $request->method() === 'PATCH' => Http::response(['id' => 'patched']),
            $request->method() === 'DELETE' => Http::response(null, 204),
            $request->method() === 'GET' && str_contains($url, '/events') => Http::response(['items' => $events]),
            default => Http::response([], 404),
        };
    });
}

/** @return Collection<int, Request> */
function googleCalls(string $method, string $fragment = ''): Collection
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === $method && str_contains($request->url(), 'googleapis.com/calendar') && str_contains($request->url(), $fragment))
        ->values();
}

function plannedSession(User $user, ?Task $task = null, string $start = '2026-10-07 14:00:00'): CalendarSession
{
    $task ??= $user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);
    $session = new CalendarSession(['starts_at' => $start, 'ends_at' => date('Y-m-d H:i:s', strtotime($start) + 3600)]);
    $session->user()->associate($user);
    $session->task()->associate($task);
    $session->save();

    return $session;
}

function weeklyRoutine(User $user, array $attributes = []): Routine
{
    return Routine::factory()->for($user)->create(array_merge(['title' => 'Prepare breakfast', 'starts_on' => '2026-10-05'], $attributes));
}

/** Creates a session without triggering a sync, and returns its id. */
function plannedSessionWithoutObserver(User $user): int
{
    return CalendarSession::withoutEvents(fn () => plannedSession($user)->id);
}
