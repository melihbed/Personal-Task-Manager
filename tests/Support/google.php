<?php

use App\Models\CalendarSession;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Google Calendar test helpers
|--------------------------------------------------------------------------
|
| Shared by the Google test files. Loaded from tests/Pest.php.
|
*/

/** A user with a connected Google account that previews the calendar "primary". */
function googleUser(array $account = []): User
{
    $user = User::factory()->create();
    $user->googleAccount()->create(array_merge([
        'email' => 'me@example.com',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addHour(),
        'import_calendar_ids' => ['primary'],
    ], $account));

    return $user;
}

/**
 * What the fake Google answers with. Tests can change these midway, for example to simulate an event that was
 * deleted in Google between two page loads.
 */
class GoogleFake
{
    /** @var array<string, int> "METHOD fragment" => status to return instead */
    public static array $failures = [];

    /** @var list<array<string, mixed>> what listing a calendar returns */
    public static array $events = [];

    /** @var array<string, array<string, mixed>> an event id => the event returned when it is fetched alone */
    public static array $single = [];

    public static int $created = 0;
}

/**
 * Pretends to be Google. Created events get ids "evt-1", "evt-2" ...; $failures maps "METHOD fragment" to a status
 * code to return instead. $events is what listing a calendar returns; $single maps an event id to the event
 * returned when it is fetched alone.
 *
 * @param  array<string, int>  $failures
 * @param  list<array<string, mixed>>  $events
 * @param  array<string, array<string, mixed>>  $single
 */
function fakeGoogle(array $failures = [], array $events = [], array $single = []): void
{
    GoogleFake::$failures = $failures;
    GoogleFake::$events = $events;
    GoogleFake::$single = $single;
    GoogleFake::$created = 0;

    Http::fake(function (Request $request) {
        $url = $request->url();

        foreach (GoogleFake::$failures as $key => $status) {
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
            $request->method() === 'GET' && preg_match('#/calendars/[^/]+/events/([^/?]+)#', $url, $match) === 1 => isset(GoogleFake::$single[urldecode($match[1])])
                ? Http::response(GoogleFake::$single[urldecode($match[1])])
                : Http::response(['error' => ['message' => 'Not Found']], 404),
            $request->method() === 'POST' && str_ends_with($url, '/events') => Http::response(['id' => 'evt-'.++GoogleFake::$created]),
            $request->method() === 'PATCH' => Http::response(['id' => 'patched']),
            $request->method() === 'DELETE' => Http::response(null, 204),
            $request->method() === 'GET' && str_contains($url, '/events') => Http::response(['items' => GoogleFake::$events]),
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

/** Every request that changed something in Google (anything but a read). */
function googleWrites(): Collection
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() !== 'GET' && str_contains($request->url(), 'googleapis.com/calendar'))
        ->values();
}

function timedEvent(array $overrides = []): array
{
    return array_merge([
        'id' => 'ev1',
        'summary' => 'Dentist',
        'start' => ['dateTime' => '2026-10-07T15:00:00-04:00'],
        'end' => ['dateTime' => '2026-10-07T16:30:00-04:00'],
    ], $overrides);
}

function allDayEvent(array $overrides = []): array
{
    return array_merge(['id' => 'ev2', 'summary' => 'Conference', 'start' => ['date' => '2026-10-08'], 'end' => ['date' => '2026-10-10']], $overrides);
}

function seriesMaster(array $overrides = []): array
{
    return array_merge([
        'id' => 'series1',
        'summary' => 'Prepare breakfast',
        'start' => ['dateTime' => '2026-10-06T08:00:00-04:00', 'timeZone' => 'America/New_York'],
        'end' => ['dateTime' => '2026-10-06T08:45:00-04:00', 'timeZone' => 'America/New_York'],
        'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=TU,TH'],
    ], $overrides);
}

function seriesInstance(array $overrides = []): array
{
    return array_merge(seriesMaster(['recurrence' => null]), [
        'id' => 'series1_20261013T120000Z',
        'recurringEventId' => 'series1',
        'start' => ['dateTime' => '2026-10-13T08:00:00-04:00'],
        'end' => ['dateTime' => '2026-10-13T08:45:00-04:00'],
    ], $overrides);
}

function importPayload(array $overrides = []): array
{
    return array_merge(['calendar_id' => 'primary-id', 'event_id' => 'ev1', 'type' => 'task', 'responsibility_id' => null, 'timezone' => 'America/New_York'], $overrides);
}

function importer(array $account = []): User
{
    return googleUser(array_merge(['import_calendar_ids' => ['primary-id']], $account));
}

function overlayTitles(User $user): array
{
    $titles = [];

    test()->actingAs($user)->get('/?week=2026-10-05&timezone=America/New_York')
        ->assertInertia(function (Assert $page) use (&$titles) {
            $page->loadDeferredProps(function (Assert $loaded) use (&$titles) {
                $titles = collect($loaded->toArray()['props']['googleEvents'] ?? [])->pluck('title')->all();
            });
        });

    return $titles;
}

function overlayEvents(): array
{
    return [
        timedEvent(),
        allDayEvent(),
        seriesInstance(),
        seriesInstance(['id' => 'series1_20261015T120000Z', 'start' => ['dateTime' => '2026-10-15T08:00:00-04:00'], 'end' => ['dateTime' => '2026-10-15T08:45:00-04:00']]),
    ];
}
