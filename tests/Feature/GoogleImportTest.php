<?php

use App\Models\CalendarSession;
use App\Models\GoogleEventImport;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

describe('adding an event as a task', function () {
    test('a timed event becomes a task due at its start with its length as the duration', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $user = importer();
        $responsibility = $user->responsibilities()->create(['name' => 'Health']);

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['responsibility_id' => $responsibility->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Added “Dentist” as a task.');

        $task = $user->tasks()->firstOrFail();

        expect($task->title)->toBe('Dentist')
            ->and($task->due_at->utc()->toIso8601String())->toBe('2026-10-07T19:00:00+00:00')
            ->and($task->due_has_time)->toBeTrue()
            ->and($task->estimate_minutes)->toBe(90)
            ->and($task->responsibility_id)->toBe($responsibility->id)
            ->and($user->googleEventImports()->firstOrFail()->only(['google_calendar_id', 'google_event_id', 'kind', 'item_id']))
            ->toBe(['google_calendar_id' => 'primary-id', 'google_event_id' => 'ev1', 'kind' => 'task', 'item_id' => $task->id]);
    });

    test('an all-day event becomes a task with a date-only deadline on its first day', function () {
        fakeGoogle(single: ['ev2' => allDayEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'ev2']))->assertSessionHasNoErrors();

        $task = $user->tasks()->firstOrFail();

        expect($task->due_has_time)->toBeFalse()
            ->and($task->due_at->utc()->toDateString())->toBe('2026-10-08')
            ->and($task->estimate_minutes)->toBeNull();
    });

});

describe('adding an event as a work session', function () {
    test('a timed event becomes a session at its time, held by a task of the same name', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['type' => 'session']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Added “Dentist” as a work session.');

        $session = CalendarSession::where('user_id', $user->id)->firstOrFail();

        expect($session->task->title)->toBe('Dentist')
            ->and($session->task->estimate_minutes)->toBe(90)
            ->and($session->task->due_at)->toBeNull()
            ->and($session->starts_at->utc()->toIso8601String())->toBe('2026-10-07T19:00:00+00:00')
            ->and($session->ends_at->utc()->toIso8601String())->toBe('2026-10-07T20:30:00+00:00')
            ->and($user->googleEventImports()->firstOrFail()->only(['kind', 'item_id']))->toBe(['kind' => 'session', 'item_id' => $session->id]);
    });

    test('an all-day event cannot become a session', function () {
        fakeGoogle(single: ['ev2' => allDayEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'ev2', 'type' => 'session']))
            ->assertSessionHasErrors(['type' => 'An all-day event has no time to plan. Add it as a task instead.']);

        expect($user->tasks()->count())->toBe(0)->and(GoogleEventImport::count())->toBe(0);
    });

    test('an event longer than a day cannot become a session', function () {
        fakeGoogle(single: ['ev1' => timedEvent(['end' => ['dateTime' => '2026-10-09T16:30:00-04:00']])]);

        $this->actingAs(importer())->post('/integrations/google/imports', importPayload(['type' => 'session']))->assertSessionHasErrors('type');
    });
});

describe('making a routine from a repeating event', function () {
    test('a weekly event becomes a routine that repeats the same way', function () {
        fakeGoogle(single: ['series1_20261013T120000Z' => seriesInstance(), 'series1' => seriesMaster()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'series1_20261013T120000Z', 'type' => 'routine']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Added “Prepare breakfast” as a routine.');

        $routine = $user->routines()->firstOrFail();

        expect($routine->title)->toBe('Prepare breakfast')
            ->and($routine->days)->toBe([2, 4])
            ->and($routine->start_time)->toBe('08:00:00')
            ->and($routine->duration_minutes)->toBe(45)
            ->and($routine->timezone)->toBe('America/New_York')
            ->and($routine->starts_on->toDateString())->toBe('2026-10-06')
            ->and($routine->ends_on)->toBeNull()
            ->and($user->googleEventImports()->firstOrFail()->only(['google_event_id', 'kind', 'item_id']))
            ->toBe(['google_event_id' => 'series1', 'kind' => 'routine', 'item_id' => $routine->id]);
    });

    test('an end date in the rule becomes the routine end date', function () {
        fakeGoogle(single: ['series1_20261013T120000Z' => seriesInstance(), 'series1' => seriesMaster(['recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=20261222T045959Z']])]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'series1_20261013T120000Z', 'type' => 'routine']))->assertSessionHasNoErrors();

        expect($user->routines()->firstOrFail()->ends_on->toDateString())->toBe('2026-12-21');
    });

    test('rules a routine cannot express are refused and nothing is created', function (array $recurrence, string $message) {
        fakeGoogle(single: ['series1_20261013T120000Z' => seriesInstance(), 'series1' => seriesMaster(['recurrence' => $recurrence])]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'series1_20261013T120000Z', 'type' => 'routine']))
            ->assertSessionHasErrors('type');

        expect(session('errors')->first('type'))->toContain($message)
            ->and($user->routines()->count())->toBe(0)
            ->and(GoogleEventImport::count())->toBe(0);
    })->with([
        'monthly' => [['RRULE:FREQ=MONTHLY;BYMONTHDAY=5'], 'repeats monthly'],
        'every other week' => [['RRULE:FREQ=WEEKLY;INTERVAL=2'], 'every 2 weeks'],
        'a fixed count' => [['RRULE:FREQ=WEEKLY;COUNT=4'], 'fixed number of times'],
    ]);

    test('an event that does not repeat cannot become a routine', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);

        $this->actingAs(importer())->post('/integrations/google/imports', importPayload(['type' => 'routine']))
            ->assertSessionHasErrors(['type' => 'This event does not repeat, so it cannot become a routine.']);
    });

    test('a repeating event that has already ended cannot become a routine', function () {
        fakeGoogle(single: ['series1_20261013T120000Z' => seriesInstance(), 'series1' => seriesMaster(['recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=20261001']])]);

        $this->actingAs(importer())->post('/integrations/google/imports', importPayload(['event_id' => 'series1_20261013T120000Z', 'type' => 'routine']))
            ->assertSessionHasErrors(['type' => 'This repeating event has already ended.']);
    });
});

describe('guarding the import', function () {
    test('guests cannot import', function () {
        $this->post('/integrations/google/imports', importPayload())->assertRedirect('/login');
    });

    test('importing needs a connected account that works', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);

        $this->actingAs(User::factory()->create())->post('/integrations/google/imports', importPayload())->assertNotFound();
        $this->actingAs(importer(['needs_reconnect' => true]))->post('/integrations/google/imports', importPayload())->assertNotFound();
    });

    test('only events from the calendars shown in the planner can be imported', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['calendar_id' => 'someone-elses']))
            ->assertSessionHasErrors(['type' => 'That calendar is not one of the calendars shown in the planner.']);

        expect(googleCalls('GET', 'someone-elses'))->toHaveCount(0)->and($user->tasks()->count())->toBe(0);
    });

    test('the same event cannot be added twice', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload())->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['type' => 'session']))
            ->assertSessionHasErrors(['type' => 'This event is already in your planner.']);

        expect($user->tasks()->count())->toBe(1);
    });

    test('another event of an imported series cannot be made a second routine', function () {
        fakeGoogle(single: [
            'series1_20261013T120000Z' => seriesInstance(),
            'series1_20261015T120000Z' => seriesInstance(['id' => 'series1_20261015T120000Z']),
            'series1' => seriesMaster(),
        ]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'series1_20261013T120000Z', 'type' => 'routine']))->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'series1_20261015T120000Z', 'type' => 'routine']))
            ->assertSessionHasErrors(['type' => 'This event is already in your planner.']);

        expect($user->routines()->count())->toBe(1);
    });

    test('the request is validated', function (array $overrides, string $field) {
        fakeGoogle(single: ['ev1' => timedEvent()]);

        $this->actingAs(importer())->post('/integrations/google/imports', importPayload($overrides))->assertSessionHasErrors($field);
    })->with([
        'an unknown type' => [['type' => 'meeting'], 'type'],
        'no event' => [['event_id' => ''], 'event_id'],
        'no calendar' => [['calendar_id' => ''], 'calendar_id'],
        'a bad timezone' => [['timezone' => 'Mars/Base'], 'timezone'],
    ]);

    test('a responsibility must be the users own and active', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $other = User::factory()->create()->responsibilities()->create(['name' => 'Private']);
        $user = importer();
        $archived = $user->responsibilities()->create(['name' => 'Old']);
        $archived->archived_at = now();
        $archived->save();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['responsibility_id' => $other->id]))->assertSessionHasErrors('responsibility_id');
        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['responsibility_id' => $archived->id]))->assertSessionHasErrors('responsibility_id');
    });

    test('an event that is gone, cancelled or unreadable is reported', function (array $failures, array $single, string $message) {
        fakeGoogle($failures, single: $single);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload())->assertSessionHasErrors(['type' => $message]);

        expect($user->tasks()->count())->toBe(0);
    })->with([
        'deleted in Google' => [[], [], 'That event is no longer in Google Calendar.'],
        'cancelled' => [[], ['ev1' => timedEvent(['status' => 'cancelled'])], 'That event is no longer in Google Calendar.'],
        'a Google error' => [['GET /events/ev1' => 500], [], 'Could not read that event from Google. Please try again.'],
    ]);
});

describe('what the calendar shows after an import', function () {
    test('an imported event leaves the overlay, and the others stay', function () {
        fakeGoogle(events: overlayEvents(), single: ['ev1' => timedEvent()]);
        $user = importer();
        expect(overlayTitles($user))->toBe(['Dentist', 'Conference', 'Prepare breakfast', 'Prepare breakfast']);

        $this->actingAs($user)->post('/integrations/google/imports', importPayload())->assertSessionHasNoErrors();

        expect(overlayTitles($user))->toBe(['Conference', 'Prepare breakfast', 'Prepare breakfast']);
    });

    test('importing a routine hides every event of its series', function () {
        fakeGoogle(events: overlayEvents(), single: ['series1_20261013T120000Z' => seriesInstance(), 'series1' => seriesMaster()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'series1_20261013T120000Z', 'type' => 'routine']))->assertSessionHasNoErrors();

        expect(overlayTitles($user))->toBe(['Dentist', 'Conference']);
    });

    test('deleting the copy brings the Google event back', function (string $type) {
        fakeGoogle(events: [timedEvent()], single: ['ev1' => timedEvent()]);
        $user = importer();
        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['type' => $type]))->assertSessionHasNoErrors();
        expect(overlayTitles($user))->toBe([]);

        if ($type === 'task') {
            $user->tasks()->firstOrFail()->delete();
        } else {
            CalendarSession::where('user_id', $user->id)->firstOrFail()->delete();
        }

        expect(overlayTitles($user))->toBe(['Dentist'])->and(GoogleEventImport::count())->toBe(0);
    })->with(['task', 'session']);

    test('deleting an imported routine brings its series back', function () {
        fakeGoogle(events: [seriesInstance()], single: ['series1_20261013T120000Z' => seriesInstance(), 'series1' => seriesMaster()]);
        $user = importer();
        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'series1_20261013T120000Z', 'type' => 'routine']))->assertSessionHasNoErrors();

        $this->actingAs($user)->delete('/routines/'.Routine::firstOrFail()->id)->assertSessionHasNoErrors();

        expect(overlayTitles($user))->toBe(['Prepare breakfast']);
    });

    test('deleting the task of an imported session brings the event back', function () {
        fakeGoogle(events: [timedEvent()], single: ['ev1' => timedEvent()]);
        $user = importer();
        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['type' => 'session']))->assertSessionHasNoErrors();

        $this->actingAs($user)->delete('/tasks/'.Task::firstOrFail()->id)->assertSessionHasNoErrors();

        expect(overlayTitles($user))->toBe(['Dentist']);
    });
});

describe('event details for the dialog', function () {
    test('events carry what the details dialog shows', function () {
        fakeGoogle(events: [timedEvent([
            'location' => 'Main Street Dental',
            'description' => '<p>Bring <b>forms</b>.</p><br>See you &amp; thanks',
            'attendees' => [['self' => true], []],
        ]), seriesInstance()]);

        $this->actingAs(importer())->get('/?week=2026-10-05&timezone=America/New_York')
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('googleEvents.0.calendar_id', 'primary-id')
                ->where('googleEvents.0.event_id', 'ev1')
                ->where('googleEvents.0.location', 'Main Street Dental')
                ->where('googleEvents.0.description', "Bring forms.\n\nSee you & thanks")
                ->where('googleEvents.0.guests', 2)
                ->where('googleEvents.0.recurring_event_id', null)
                ->where('googleEvents.1.recurring_event_id', 'series1')
                ->where('googleEvents.1.guests', 0)
                ->where('googleEvents.1.location', null)
                ->where('googleEvents.1.description', null)));
    });
});
