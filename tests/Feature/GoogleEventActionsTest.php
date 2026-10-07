<?php

use App\Models\GoogleEventImport;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function deletePayload(array $overrides = []): array
{
    return array_merge(['calendar_id' => 'primary-id', 'event_id' => 'ev1', 'scope' => 'event'], $overrides);
}

function editPayload(array $overrides = []): array
{
    return array_merge([
        'calendar_id' => 'primary-id',
        'event_id' => 'ev1',
        'scope' => 'event',
        'title' => 'Dentist (rescheduled)',
        'all_day' => false,
        'timezone' => 'America/New_York',
        'starts_at' => '2026-10-08T18:00:00.000Z', // 2:00 PM in New York
        'ends_at' => '2026-10-08T19:00:00.000Z',
    ], $overrides);
}

function hidePayload(array $overrides = []): array
{
    return array_merge(['calendar_id' => 'primary-id', 'event_id' => 'ev1', 'scope' => 'event', 'recurring_event_id' => null, 'title' => 'Dentist'], $overrides);
}

describe('deleting an event from Google Calendar', function () {
    test('a single event is deleted in Google', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $user = importer();

        $this->actingAs($user)->delete('/integrations/google/events', deletePayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Deleted from Google Calendar.');

        expect(googleCalls('DELETE'))->toHaveCount(1)
            ->and(googleCalls('DELETE', '/calendars/primary-id/events/ev1'))->toHaveCount(1);
    });

    test('one event of a repeating series is deleted on its own', function () {
        fakeGoogle(single: ['series1_20261013T120000Z' => seriesInstance()]);

        $this->actingAs(importer())->delete('/integrations/google/events', deletePayload(['event_id' => 'series1_20261013T120000Z']))->assertSessionHasNoErrors();

        expect(googleCalls('DELETE'))->toHaveCount(1)
            ->and(googleCalls('DELETE', '/events/series1_20261013T120000Z'))->toHaveCount(1);
    });

    test('the whole series is deleted by deleting its first event', function () {
        fakeGoogle(single: ['series1_20261013T120000Z' => seriesInstance()]);

        $this->actingAs(importer())->delete('/integrations/google/events', deletePayload(['event_id' => 'series1_20261013T120000Z', 'scope' => 'series']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Deleted the series from Google Calendar.');

        expect(googleCalls('DELETE'))->toHaveCount(1)
            ->and(googleCalls('DELETE', '/events/series1'))->toHaveCount(1)
            ->and(googleCalls('DELETE', 'T120000Z'))->toHaveCount(0);
    });

    test('there is no series to delete for an event that does not repeat', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);

        $this->actingAs(importer())->delete('/integrations/google/events', deletePayload(['scope' => 'series']))
            ->assertSessionHasErrors(['event' => 'This event does not repeat, so there is no series.']);

        expect(googleCalls('DELETE'))->toHaveCount(0);
    });

    test('an event that is already gone counts as deleted', function () {
        fakeGoogle(['DELETE /events/ev1' => 410], single: ['ev1' => timedEvent()]);

        $this->actingAs(importer())->delete('/integrations/google/events', deletePayload())->assertSessionHasNoErrors();
    });

    test('Google refusing the delete is explained', function (array $failures, string $message) {
        fakeGoogle($failures, single: ['ev1' => timedEvent()]);

        $this->actingAs(importer())->delete('/integrations/google/events', deletePayload(['scope' => 'event']))->assertSessionHasErrors(['event' => $message]);
    })->with([
        'a read-only calendar or a guest' => [['DELETE /events/ev1' => 403], 'Google does not let you change this event. Its calendar may be read-only, or you may only be a guest.'],
        'a Google error' => [['DELETE /events/ev1' => 500], 'Google Calendar could not do that. Please try again.'],
    ]);

    test('the calendar shows the change straight away, not a stale copy', function () {
        fakeGoogle(events: [timedEvent(), allDayEvent()], single: ['ev1' => timedEvent()]);
        $user = importer();
        expect(overlayTitles($user))->toBe(['Dentist', 'Conference']);

        GoogleFake::$events = [allDayEvent()];
        $this->actingAs($user)->delete('/integrations/google/events', deletePayload())->assertSessionHasNoErrors();

        expect(overlayTitles($user))->toBe(['Conference']);
    });

    test('an event hidden earlier is forgotten once it is deleted', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $user = importer();
        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();
        expect(GoogleEventImport::where('kind', 'hidden')->count())->toBe(1);

        $this->actingAs($user)->delete('/integrations/google/events', deletePayload())->assertSessionHasNoErrors();

        expect(GoogleEventImport::where('kind', 'hidden')->count())->toBe(0);
    });
});

describe('changing an event in Google Calendar', function () {
    test('one event gets a new title and time, in the timezone you chose', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);

        $this->actingAs(importer())->patch('/integrations/google/events', editPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Saved to Google Calendar.');

        $patch = googleCalls('PATCH', '/calendars/primary-id/events/ev1');

        expect($patch)->toHaveCount(1)
            ->and($patch->first()->data())->toBe([
                'summary' => 'Dentist (rescheduled)',
                'start' => ['dateTime' => '2026-10-08T14:00:00', 'timeZone' => 'America/New_York'],
                'end' => ['dateTime' => '2026-10-08T15:00:00', 'timeZone' => 'America/New_York'],
            ]);
    });

    test('an all-day event gets its last day turned into Google exclusive end date', function () {
        fakeGoogle(single: ['ev2' => allDayEvent()]);

        $this->actingAs(importer())->patch('/integrations/google/events', [
            'calendar_id' => 'primary-id', 'event_id' => 'ev2', 'scope' => 'event', 'title' => 'Conference week', 'all_day' => true,
            'timezone' => 'America/New_York', 'start_date' => '2026-10-08', 'end_date' => '2026-10-10',
        ])->assertSessionHasNoErrors();

        expect(googleCalls('PATCH')->first()->data())->toBe([
            'summary' => 'Conference week',
            'start' => ['date' => '2026-10-08'],
            'end' => ['date' => '2026-10-11'],
        ]);
    });

    test('one event of a repeating series can be changed on its own', function () {
        fakeGoogle(single: ['series1_20261013T120000Z' => seriesInstance()]);

        $this->actingAs(importer())->patch('/integrations/google/events', editPayload(['event_id' => 'series1_20261013T120000Z']))->assertSessionHasNoErrors();

        expect(googleCalls('PATCH'))->toHaveCount(1)
            ->and(googleCalls('PATCH', '/events/series1_20261013T120000Z'))->toHaveCount(1);
    });

    test('the whole series takes the new title and time of day, and keeps its days', function () {
        fakeGoogle(single: ['series1_20261013T120000Z' => seriesInstance(), 'series1' => seriesMaster()]);

        // The edited event is moved to 2:00 PM for 90 minutes, on a different date than the series' first day.
        $this->actingAs(importer())->patch('/integrations/google/events', editPayload([
            'event_id' => 'series1_20261013T120000Z', 'scope' => 'series', 'title' => 'Cook breakfast',
            'starts_at' => '2026-10-15T18:00:00.000Z', 'ends_at' => '2026-10-15T19:30:00.000Z',
        ]))->assertSessionHasNoErrors();

        $patch = googleCalls('PATCH');

        expect($patch)->toHaveCount(1)
            ->and($patch->first()->url())->toContain('/events/series1')
            ->and($patch->first()->data())->toBe([
                'summary' => 'Cook breakfast',
                'start' => ['dateTime' => '2026-10-06T14:00:00', 'timeZone' => 'America/New_York'],
                'end' => ['dateTime' => '2026-10-06T15:30:00', 'timeZone' => 'America/New_York'],
            ]);
    });

    test('an all-day series only takes the new title', function () {
        fakeGoogle(single: ['rec_day' => allDayEvent(['id' => 'rec_day', 'recurringEventId' => 'dayseries'])]);

        $this->actingAs(importer())->patch('/integrations/google/events', [
            'calendar_id' => 'primary-id', 'event_id' => 'rec_day', 'scope' => 'series', 'title' => 'Conference week', 'all_day' => true,
            'timezone' => 'America/New_York', 'start_date' => '2026-10-08', 'end_date' => '2026-10-09',
        ])->assertSessionHasNoErrors();

        expect(googleCalls('PATCH')->first()->data())->toBe(['summary' => 'Conference week'])
            ->and(googleCalls('PATCH', '/events/dayseries'))->toHaveCount(1);
    });

    test('there is no series to change for an event that does not repeat', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);

        $this->actingAs(importer())->patch('/integrations/google/events', editPayload(['scope' => 'series']))
            ->assertSessionHasErrors(['event' => 'This event does not repeat, so there is no series.']);

        expect(googleWrites())->toHaveCount(0);
    });

    test('an event that changed kind in Google since the dialog opened is not overwritten', function () {
        fakeGoogle(single: ['ev2' => allDayEvent()]);

        $this->actingAs(importer())->patch('/integrations/google/events', editPayload(['event_id' => 'ev2']))
            ->assertSessionHasErrors(['event' => 'This event was changed in Google Calendar. Close this and open it again.']);

        expect(googleWrites())->toHaveCount(0);
    });

    test('Google refusing the change is explained, and a vanished event is reported', function (array $failures, array $single, string $message) {
        fakeGoogle($failures, single: $single);

        $this->actingAs(importer())->patch('/integrations/google/events', editPayload())->assertSessionHasErrors(['event' => $message]);
    })->with([
        'a read-only calendar or a guest' => [['PATCH /events/ev1' => 403], ['ev1' => timedEvent()], 'Google does not let you change this event. Its calendar may be read-only, or you may only be a guest.'],
        'a Google error' => [['PATCH /events/ev1' => 500], ['ev1' => timedEvent()], 'Google Calendar could not do that. Please try again.'],
        'deleted in Google' => [[], [], 'That event is no longer in Google Calendar.'],
        'cancelled in Google' => [[], ['ev1' => timedEvent(['status' => 'cancelled'])], 'That event is no longer in Google Calendar.'],
    ]);

    test('the calendar shows the new title straight away, not a stale copy', function () {
        fakeGoogle(events: [timedEvent()], single: ['ev1' => timedEvent()]);
        $user = importer();
        expect(overlayTitles($user))->toBe(['Dentist']);

        GoogleFake::$events = [timedEvent(['summary' => 'Dentist (rescheduled)'])];
        $this->actingAs($user)->patch('/integrations/google/events', editPayload())->assertSessionHasNoErrors();

        expect(overlayTitles($user))->toBe(['Dentist (rescheduled)']);
    });

    test('the edit is validated', function (array $overrides, string $field) {
        fakeGoogle(single: ['ev1' => timedEvent(), 'ev2' => allDayEvent()]);

        $this->actingAs(importer())->patch('/integrations/google/events', editPayload($overrides))->assertSessionHasErrors($field);
    })->with([
        'no title' => [['title' => ''], 'title'],
        'ending before it starts' => [['ends_at' => '2026-10-08T17:00:00.000Z'], 'ends_at'],
        'no start time' => [['starts_at' => null], 'starts_at'],
        'a start without an offset' => [['starts_at' => '2026-10-08T14:00:00'], 'starts_at'],
        'a bad timezone' => [['timezone' => 'Mars/Base'], 'timezone'],
        'an unknown scope' => [['scope' => 'everything'], 'scope'],
        'an all-day event with the last day before the first' => [['all_day' => true, 'start_date' => '2026-10-10', 'end_date' => '2026-10-08'], 'end_date'],
        'an all-day event with no dates' => [['all_day' => true], 'start_date'],
    ]);
});

describe('who may change Google events', function () {
    test('guests are sent to log in', function () {
        $this->patch('/integrations/google/events', editPayload())->assertRedirect('/login');
        $this->delete('/integrations/google/events', deletePayload())->assertRedirect('/login');
        $this->post('/integrations/google/hidden', hidePayload())->assertRedirect('/login');
    });

    test('a connected account that works is needed', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);

        foreach ([User::factory()->create(), importer(['needs_reconnect' => true])] as $user) {
            $this->actingAs($user)->patch('/integrations/google/events', editPayload())->assertNotFound();
            $this->actingAs($user)->delete('/integrations/google/events', deletePayload())->assertNotFound();
            $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())->assertNotFound();
        }
    });

    test('only calendars shown in the planner can be changed', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $user = importer();

        $this->actingAs($user)->patch('/integrations/google/events', editPayload(['calendar_id' => 'someone-elses']))->assertSessionHasErrors('event');
        $this->actingAs($user)->delete('/integrations/google/events', deletePayload(['calendar_id' => 'someone-elses']))->assertSessionHasErrors('event');
        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload(['calendar_id' => 'someone-elses']))->assertSessionHasErrors('event');

        expect(googleCalls('GET', 'someone-elses'))->toHaveCount(0)->and(googleWrites())->toHaveCount(0);
    });
});

describe('hiding a Google event from the planner', function () {
    test('a hidden event leaves the calendar and nothing changes in Google', function () {
        fakeGoogle(events: [timedEvent(), allDayEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Hid the event from your planner.');

        expect(overlayTitles($user))->toBe(['Conference'])
            ->and(googleWrites())->toHaveCount(0)
            ->and($user->googleEventImports()->firstOrFail()->only(['kind', 'google_event_id', 'label', 'item_id']))
            ->toBe(['kind' => 'hidden', 'google_event_id' => 'ev1', 'label' => 'Dentist', 'item_id' => 0]);
    });

    test('hiding a whole series hides every event of it', function () {
        fakeGoogle(events: overlayEvents());
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload([
            'event_id' => 'series1_20261013T120000Z', 'scope' => 'series', 'recurring_event_id' => 'series1', 'title' => 'Prepare breakfast',
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', 'Hid all events of the series from your planner.');

        expect(overlayTitles($user))->toBe(['Dentist', 'Conference'])
            ->and($user->googleEventImports()->firstOrFail()->label)->toBe('Prepare breakfast (all events)');
    });

    test('hiding the same event twice keeps one record', function () {
        fakeGoogle();
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();

        expect($user->googleEventImports()->count())->toBe(1);
    });

    test('hiding is validated', function (array $overrides, string $field) {
        fakeGoogle();

        $this->actingAs(importer())->post('/integrations/google/hidden', hidePayload($overrides))->assertSessionHasErrors($field);
    })->with([
        'a series without its id' => [['scope' => 'series', 'recurring_event_id' => null], 'recurring_event_id'],
        'an unknown scope' => [['scope' => 'everything'], 'scope'],
        'no event' => [['event_id' => ''], 'event_id'],
    ]);

    test('hidden events are listed in settings and can be shown again', function () {
        fakeGoogle(events: [timedEvent()]);
        $user = importer();
        $this->actingAs($user)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();
        $hidden = $user->googleEventImports()->firstOrFail();

        $this->actingAs($user)->get('/integrations/google')
            ->assertInertia(fn (Assert $page) => $page->where('hiddenEvents', [['id' => $hidden->id, 'label' => 'Dentist']]));

        $this->actingAs($user)->delete("/integrations/google/hidden/{$hidden->id}")
            ->assertSessionHas('status', 'The event is shown in your planner again.');

        expect(overlayTitles($user))->toBe(['Dentist']);
        $this->actingAs($user)->get('/integrations/google')->assertInertia(fn (Assert $page) => $page->where('hiddenEvents', []));
    });

    test('only a hidden event of your own can be shown again', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $owner = importer();
        $this->actingAs($owner)->post('/integrations/google/hidden', hidePayload())->assertSessionHasNoErrors();
        $hidden = $owner->googleEventImports()->firstOrFail();

        $this->actingAs(importer())->delete("/integrations/google/hidden/{$hidden->id}")->assertNotFound();

        // A planner item copied from an event is not a hidden event either.
        $copy = $owner->googleEventImports()->create(['google_calendar_id' => 'primary-id', 'google_event_id' => 'ev9', 'kind' => 'task', 'item_id' => 5]);
        $this->actingAs($owner)->delete("/integrations/google/hidden/{$copy->id}")->assertNotFound();

        expect(GoogleEventImport::count())->toBe(2);
    });
});
