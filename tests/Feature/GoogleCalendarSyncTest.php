<?php

use App\Jobs\RemoveGoogleEvents;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleApiException;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleReconnectRequired;
use App\Services\GoogleCalendar\GoogleSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

describe('sessions', function () {
    test('a new session is pushed once and remembered', function () {
        fakeGoogle();
        $user = googleUser();

        $session = plannedSession($user);

        expect(googleCalls('POST', '/calendars/cal-1/events'))->toHaveCount(1)
            ->and(googleCalls('POST')->first()['summary'])->toBe('Study')
            ->and(googleCalls('POST')->first()['extendedProperties']['private']['planner'])->toBe('session')
            ->and($user->googleEventLinks()->where('kind', 'session')->where('item_id', $session->id)->value('google_event_id'))->toBe('evt-1');
    });

    test('syncing an unchanged session again sends nothing', function () {
        fakeGoogle();
        $user = googleUser();
        $session = plannedSession($user);
        Http::fake();

        app(GoogleSync::class)->sync($user->fresh(), 'session', $session->id);

        Http::assertNothingSent();
    });

    test('moving a session patches the same Google event', function () {
        fakeGoogle();
        $user = googleUser();
        $session = plannedSession($user);

        $session->starts_at = '2026-10-08 09:00:00';
        $session->ends_at = '2026-10-08 10:00:00';
        $session->save();

        expect(googleCalls('POST'))->toHaveCount(1)
            ->and(googleCalls('PATCH', '/events/evt-1'))->toHaveCount(1)
            ->and(googleCalls('PATCH')->first()['start']['dateTime'])->toBe('2026-10-08T09:00:00Z');
    });

    test('deleting a session deletes its Google event and link', function () {
        fakeGoogle();
        $user = googleUser();
        $session = plannedSession($user);

        $session->delete();

        expect(googleCalls('DELETE', '/events/evt-1'))->toHaveCount(1)
            ->and($user->googleEventLinks()->count())->toBe(0);
    });

    test('nothing is sent for a user who has not connected Google', function () {
        fakeGoogle();
        $user = User::factory()->create();

        plannedSession($user);

        Http::assertNothingSent();
    });

    test('nothing is sent while the connection needs to be renewed', function () {
        fakeGoogle();
        $user = googleUser(['needs_reconnect' => true]);

        plannedSession($user);

        Http::assertNothingSent();
    });

    test('turning the sessions switch off removes them from Google', function () {
        fakeGoogle();
        $user = googleUser();
        $session = plannedSession($user);

        $user->googleAccount->update(['push_sessions' => false]);
        app(GoogleSync::class)->sync($user->fresh(), 'session', $session->id);

        expect(googleCalls('DELETE', '/events/evt-1'))->toHaveCount(1)
            ->and($user->googleEventLinks()->count())->toBe(0);
    });

    test('changing the target calendar moves the event', function () {
        fakeGoogle();
        $user = googleUser();
        $session = plannedSession($user);

        $user->googleAccount->update(['calendar_id' => 'cal-2']);
        app(GoogleSync::class)->sync($user->fresh(), 'session', $session->id);

        expect(googleCalls('DELETE', '/calendars/cal-1/events/evt-1'))->toHaveCount(1)
            ->and(googleCalls('POST', '/calendars/cal-2/events'))->toHaveCount(1)
            ->and($user->googleEventLinks()->value('google_calendar_id'))->toBe('cal-2');
    });

    test('an event deleted in Google is created again', function () {
        fakeGoogle(['PATCH /events/evt-1' => 404]);
        $user = googleUser();
        $session = plannedSession($user);

        $session->starts_at = '2026-10-08 09:00:00';
        $session->ends_at = '2026-10-08 10:00:00';
        $session->save();

        expect(googleCalls('POST'))->toHaveCount(2)
            ->and($user->googleEventLinks()->value('google_event_id'))->toBe('evt-2');
    });

    test('a Google server error is thrown so the job can retry', function () {
        fakeGoogle(['POST /events' => 503]);
        $user = googleUser();

        expect(fn () => app(GoogleSync::class)->sync($user, 'session', plannedSessionWithoutObserver($user)))
            ->toThrow(GoogleApiException::class);
    });

    test('a task in an archived responsibility is not pushed', function () {
        fakeGoogle();
        $user = googleUser();
        $responsibility = $user->responsibilities()->create(['name' => 'Old']);
        $responsibility->archived_at = now();
        $responsibility->save();
        $task = $user->tasks()->create(['title' => 'Hidden', 'priority' => 'normal']);
        $task->responsibility_id = $responsibility->id;
        $task->save();

        plannedSession($user, $task);

        Http::assertNothingSent();
    });
});

describe('deadlines', function () {
    test('a task with a deadline is pushed as a short event, and one without is not', function () {
        fakeGoogle();
        $user = googleUser();

        $user->tasks()->create(['title' => 'No deadline', 'priority' => 'normal']);
        expect(googleCalls('POST'))->toHaveCount(0);

        $user->tasks()->create(['title' => 'Call', 'priority' => 'normal', 'due_at' => '2026-10-07T18:00:00Z']);

        expect(googleCalls('POST'))->toHaveCount(1)
            ->and(googleCalls('POST')->first()['summary'])->toBe('Due: Call')
            ->and(googleCalls('POST')->first()['transparency'])->toBe('transparent');
    });

    test('a date-only deadline is pushed as an all-day event', function () {
        fakeGoogle();
        $user = googleUser();

        $user->tasks()->create(['title' => 'Report', 'priority' => 'normal', 'due_at' => '2026-10-07T12:00:00Z', 'due_has_time' => false]);

        expect(googleCalls('POST')->first()['start'])->toBe(['date' => '2026-10-07']);
    });

    test('completing a task removes its deadline and reopening puts it back', function () {
        fakeGoogle();
        $user = googleUser();
        $task = $user->tasks()->create(['title' => 'Call', 'priority' => 'normal', 'due_at' => '2026-10-07T18:00:00Z']);

        $task->completed_at = now();
        $task->save();
        expect(googleCalls('DELETE', '/events/evt-1'))->toHaveCount(1);

        $task->completed_at = null;
        $task->save();
        expect(googleCalls('POST'))->toHaveCount(2);
    });

    test('deleting a task removes its deadline and the events of its sessions', function () {
        fakeGoogle();
        $user = googleUser();
        $task = $user->tasks()->create(['title' => 'Study', 'priority' => 'normal', 'due_at' => '2026-10-07T18:00:00Z']);
        plannedSession($user, $task);
        plannedSession($user, $task, '2026-10-08 14:00:00');
        expect($user->googleEventLinks()->count())->toBe(3);

        $task->delete();

        expect(googleCalls('DELETE'))->toHaveCount(3)
            ->and($user->googleEventLinks()->count())->toBe(0);
    });

    test('clearing completed tasks removes their Google events', function () {
        fakeGoogle();
        $user = googleUser();
        $task = $user->tasks()->create(['title' => 'Done thing', 'priority' => 'normal']);
        plannedSession($user, $task);
        $task->completed_at = now();
        $task->save();

        $this->actingAs($user)->delete('/completed-tasks')->assertSessionHasNoErrors();

        expect(googleCalls('DELETE'))->toHaveCount(1)
            ->and($user->googleEventLinks()->count())->toBe(0);
    });
});

describe('routines', function () {
    test('a routine is pushed as one recurring event', function () {
        fakeGoogle();
        $user = googleUser();

        weeklyRoutine($user);

        expect(googleCalls('POST'))->toHaveCount(1)
            ->and(googleCalls('POST')->first()['recurrence'][0])->toBe('RRULE:FREQ=WEEKLY;BYDAY=TU,TH')
            ->and(googleCalls('POST')->first()['start'])->toBe(['dateTime' => '2026-10-06T08:00:00', 'timeZone' => 'America/New_York']);
    });

    test('skipping a day adds an EXDATE to the recurring event', function () {
        fakeGoogle();
        $user = googleUser();
        $routine = weeklyRoutine($user);

        $routine->occurrences()->create(['occurs_on' => '2026-10-08', 'skipped' => true]);

        expect(googleCalls('PATCH', '/events/evt-1'))->toHaveCount(1)
            ->and(googleCalls('PATCH')->first()['recurrence'][1])->toBe('EXDATE;TZID=America/New_York:20261008T080000');
    });

    test('moving a day patches that instance, and putting it back restores its usual time', function () {
        fakeGoogle();
        $user = googleUser();
        $routine = weeklyRoutine($user);

        $occurrence = $routine->occurrences()->create(['occurs_on' => '2026-10-06', 'starts_at' => '2026-10-06 14:00:00', 'ends_at' => '2026-10-06 15:00:00']);

        $moved = googleCalls('PATCH', '/events/evt-1_20261006T120000Z');
        expect($moved)->toHaveCount(1)
            ->and($moved->first()['start']['dateTime'])->toBe('2026-10-06T14:00:00Z');

        $occurrence->delete();

        $restored = googleCalls('PATCH', '/events/evt-1_20261006T120000Z');
        expect($restored)->toHaveCount(2)
            ->and($restored->last()['start']['dateTime'])->toBe('2026-10-06T12:00:00Z')
            ->and($user->googleEventLinks()->where('kind', 'occurrence')->count())->toBe(0);
    });

    test('a moved day that is already applied is not sent again', function () {
        fakeGoogle();
        $user = googleUser();
        $routine = weeklyRoutine($user);
        $routine->occurrences()->create(['occurs_on' => '2026-10-06', 'starts_at' => '2026-10-06 14:00:00', 'ends_at' => '2026-10-06 15:00:00']);
        Http::fake();

        app(GoogleSync::class)->sync($user->fresh(), 'routine', $routine->id);

        Http::assertNothingSent();
    });

    test('editing a routine patches the recurring event once and applies its moved days again', function () {
        fakeGoogle();
        $user = googleUser();
        $routine = weeklyRoutine($user);
        $routine->occurrences()->create(['occurs_on' => '2026-10-06', 'skipped' => true]);

        $this->actingAs($user)->patch("/routines/{$routine->id}", [
            'title' => 'Cook breakfast', 'days' => [2, 3, 4], 'start_time' => '07:30', 'duration_minutes' => 45,
            'timezone' => 'America/New_York', 'starts_on' => '2026-10-05', 'ends_on' => null,
        ])->assertSessionHasNoErrors();

        $patches = googleCalls('PATCH', '/events/evt-1');
        $last = $patches->last();

        expect($last['summary'])->toBe('Cook breakfast')
            ->and($last['recurrence'][0])->toBe('RRULE:FREQ=WEEKLY;BYDAY=TU,WE,TH')
            ->and($last['start']['dateTime'])->toBe('2026-10-06T07:30:00')
            ->and($last['recurrence'][1])->toBe('EXDATE;TZID=America/New_York:20261006T073000');
    });

    test('deleting a routine deletes its Google event and its moved day links', function () {
        fakeGoogle();
        $user = googleUser();
        $routine = weeklyRoutine($user);
        $routine->occurrences()->create(['occurs_on' => '2026-10-06', 'starts_at' => '2026-10-06 14:00:00', 'ends_at' => '2026-10-06 15:00:00']);

        $routine->delete();

        expect(googleCalls('DELETE', '/events/evt-1'))->toHaveCount(1)
            ->and($user->googleEventLinks()->count())->toBe(0);
    });
});

describe('the Google client', function () {
    test('an expired token is refreshed before the request', function () {
        fakeGoogle();
        $user = googleUser(['expires_at' => now()->subMinute()]);

        (new GoogleCalendarClient($user->googleAccount))->calendars();

        $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com/token'));
        $listCall = googleCalls('GET', 'calendarList')->first();

        expect($tokenCalls)->toHaveCount(1)
            ->and($listCall->header('Authorization'))->toBe(['Bearer new-token'])
            ->and($user->googleAccount->fresh()->access_token)->toBe('new-token');
    });

    test('a rejected token is refreshed once and the request retried', function () {
        $first = true;
        Http::fake(function (Request $request) use (&$first) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'fresh', 'expires_in' => 3600]);
            }

            if ($first) {
                $first = false;

                return Http::response(['error' => ['message' => 'Invalid Credentials']], 401);
            }

            return Http::response(['items' => []]);
        });
        $user = googleUser();

        $calendars = (new GoogleCalendarClient($user->googleAccount))->calendars();

        expect($calendars)->toBe([])
            ->and(googleCalls('GET', 'calendarList'))->toHaveCount(2);
    });

    test('a revoked authorization marks the account as needing to reconnect', function () {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $user = googleUser(['expires_at' => now()->subMinute()]);

        expect(fn () => (new GoogleCalendarClient($user->googleAccount))->calendars())->toThrow(GoogleReconnectRequired::class);
        expect($user->googleAccount->fresh()->needs_reconnect)->toBeTrue();
    });

    test('an account without a refresh token must reconnect when its token expires', function () {
        fakeGoogle();
        $user = googleUser(['refresh_token' => null, 'expires_at' => now()->subMinute()]);

        expect(fn () => (new GoogleCalendarClient($user->googleAccount))->calendars())->toThrow(GoogleReconnectRequired::class);
    });

    test('deleting an event that is already gone is not an error', function () {
        fakeGoogle(['DELETE /events/old' => 410]);
        $user = googleUser();

        (new GoogleCalendarClient($user->googleAccount))->delete('cal-1', 'old');

        expect(googleCalls('DELETE'))->toHaveCount(1);
    });

    test('calendars are listed with whether they can be written to', function () {
        fakeGoogle();
        $user = googleUser();

        $calendars = collect((new GoogleCalendarClient($user->googleAccount))->calendars())->keyBy('id');

        expect($calendars->keys()->all())->toBe(['primary-id', 'cal-1', 'holidays'])
            ->and($calendars['primary-id']['primary'])->toBeTrue()
            ->and($calendars['cal-1']['writable'])->toBeTrue()
            ->and($calendars['holidays']['writable'])->toBeFalse();
    });

    test('events are read across pages', function () {
        $page = 0;
        Http::fake(function (Request $request) use (&$page) {
            $page++;

            return Http::response($page === 1
                ? ['items' => [['id' => 'a']], 'nextPageToken' => 'next']
                : ['items' => [['id' => 'b']]]);
        });
        $user = googleUser();

        $events = (new GoogleCalendarClient($user->googleAccount))->events('primary', now(), now()->addWeek());

        expect(array_column($events, 'id'))->toBe(['a', 'b'])
            ->and(googleCalls('GET', '/events'))->toHaveCount(2);
    });
});

test('removing all events deletes every pushed event and forgets the links', function () {
    fakeGoogle();
    $user = googleUser();
    plannedSession($user);
    weeklyRoutine($user);
    $user->tasks()->create(['title' => 'Call', 'priority' => 'normal', 'due_at' => '2026-10-07T18:00:00Z']);
    expect($user->googleEventLinks()->count())->toBe(3);

    RemoveGoogleEvents::dispatchSync($user->id);

    expect(googleCalls('DELETE'))->toHaveCount(3)
        ->and($user->googleEventLinks()->count())->toBe(0);
});

test('an event id is unique to each pushed item', function () {
    fakeGoogle();
    $user = googleUser();

    plannedSession($user);
    plannedSession($user, null, '2026-10-09 10:00:00');

    expect($user->googleEventLinks()->pluck('google_event_id')->unique())->toHaveCount(2)
        ->and(Str::startsWith($user->googleEventLinks()->first()->google_event_id, 'evt-'))->toBeTrue();
});
