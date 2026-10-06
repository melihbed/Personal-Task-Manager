<?php

use App\Models\CalendarSession;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleEventMapper;

function breakfast(array $attributes = []): Routine
{
    return Routine::factory()->make(array_merge([
        'id' => 7,
        'title' => 'Prepare breakfast',
        'days' => [2, 4],
        'start_time' => '08:00:00',
        'duration_minutes' => 60,
        'timezone' => 'America/New_York',
        'starts_on' => '2026-10-05',
        'ends_on' => null,
    ], $attributes));
}

test('a routine becomes one weekly recurring event in its own timezone', function () {
    $event = (new GoogleEventMapper)->routine(breakfast());

    expect($event['summary'])->toBe('Prepare breakfast')
        ->and($event['start'])->toBe(['dateTime' => '2026-10-06T08:00:00', 'timeZone' => 'America/New_York'])
        ->and($event['end'])->toBe(['dateTime' => '2026-10-06T09:00:00', 'timeZone' => 'America/New_York'])
        ->and($event['recurrence'])->toBe(['RRULE:FREQ=WEEKLY;BYDAY=TU,TH'])
        ->and($event['extendedProperties']['private'])->toBe(['planner' => 'routine', 'plannerId' => '7']);
});

test('the first event is on the first matching day on or after the start date', function () {
    $event = (new GoogleEventMapper)->routine(breakfast(['starts_on' => '2026-10-07'])); // a Wednesday

    expect($event['start']['dateTime'])->toBe('2026-10-08T08:00:00'); // Thursday
});

test('an end date becomes an UNTIL at the end of that day in UTC', function () {
    $rule = (new GoogleEventMapper)->recurrenceRule(breakfast(['ends_on' => '2026-12-31']));

    // 23:59:59 on Dec 31 in New York (EST, UTC-5) is 04:59:59 UTC on Jan 1.
    expect($rule)->toBe('RRULE:FREQ=WEEKLY;BYDAY=TU,TH;UNTIL=20270101T045959Z');
});

test('skipped days become EXDATEs and days that are not occurrences are ignored', function () {
    $event = (new GoogleEventMapper)->routine(breakfast(), ['2026-10-15', '2026-10-08', '2026-10-07', '2026-09-01']);

    expect($event['recurrence'])->toBe([
        'RRULE:FREQ=WEEKLY;BYDAY=TU,TH',
        'EXDATE;TZID=America/New_York:20261008T080000,20261015T080000',
    ]);
});

test('an instance id is the master id plus the original UTC start', function () {
    expect((new GoogleEventMapper)->instanceId('abc123', breakfast(), '2026-10-06'))->toBe('abc123_20261006T120000Z');
});

test('the usual times of a moved day are its rule times in UTC', function () {
    $times = (new GoogleEventMapper)->usualTimes(breakfast(), '2026-10-06');

    expect($times['start']['dateTime'])->toBe('2026-10-06T12:00:00Z')
        ->and($times['end']['dateTime'])->toBe('2026-10-06T13:00:00Z');
});

test('a timed deadline is a short event that does not block time', function () {
    $task = Task::make(['title' => 'Call', 'due_at' => '2026-10-07T18:00:00Z', 'due_has_time' => true]);
    $task->id = 11;

    $event = (new GoogleEventMapper)->deadline($task);

    expect($event['summary'])->toBe('Due: Call')
        ->and($event['start']['dateTime'])->toBe('2026-10-07T18:00:00Z')
        ->and($event['end']['dateTime'])->toBe('2026-10-07T18:15:00Z')
        ->and($event['transparency'])->toBe('transparent');
});

test('a date-only deadline is an all-day event', function () {
    $task = Task::make(['title' => 'Submit report', 'due_at' => '2026-10-07T12:00:00Z', 'due_has_time' => false]);
    $task->id = 12;

    $event = (new GoogleEventMapper)->deadline($task);

    expect($event['start'])->toBe(['date' => '2026-10-07'])
        ->and($event['end'])->toBe(['date' => '2026-10-08']);
});

test('a work session is an event at its own time titled after its task', function () {
    $user = User::factory()->create();
    $responsibility = $user->responsibilities()->create(['name' => 'Blue Mosque']);
    $task = $user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);
    $task->responsibility_id = $responsibility->id;
    $task->save();
    $session = new CalendarSession(['starts_at' => '2026-10-07 14:00:00', 'ends_at' => '2026-10-07 15:30:00']);
    $session->id = 5;

    $event = (new GoogleEventMapper)->session($session, $task->load('responsibility'));

    expect($event['summary'])->toBe('Study')
        ->and($event['description'])->toContain('Responsibility: Blue Mosque')
        ->and($event['start']['dateTime'])->toBe('2026-10-07T14:00:00Z')
        ->and($event['end']['dateTime'])->toBe('2026-10-07T15:30:00Z')
        ->and($event['extendedProperties']['private'])->toBe(['planner' => 'session', 'plannerId' => '5']);
});
