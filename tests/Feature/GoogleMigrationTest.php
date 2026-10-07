<?php

use App\Models\CalendarEvent;
use App\Models\GoogleAccount;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 18:00:00 UTC');
});

function migrate(User $user, array $payload = ['timezone' => 'America/New_York'])
{
    return test()->actingAs($user)->post('/integrations/google/migrate', $payload);
}

describe('adding one Google event as an event', function () {
    it('copies a timed event with its place and description, and it is the app\'s own from then on', function () {
        fakeGoogle(single: ['ev1' => timedEvent(['location' => 'Main St clinic', 'description' => '<b>Bring</b> the form'])]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['type' => 'event']))->assertSessionHasNoErrors()->assertSessionHas('status', 'Added “Dentist” as an event.');

        $event = CalendarEvent::firstOrFail();
        expect($event->user_id)->toBe($user->id)->and($event->title)->toBe('Dentist')->and($event->location)->toBe('Main St clinic')->and($event->notes)->toBe('Bring the form')->and($event->all_day)->toBeFalse()
            ->and($event->starts_at->toIso8601String())->toBe('2026-10-07T19:00:00+00:00')->and($event->ends_at->toIso8601String())->toBe('2026-10-07T20:30:00+00:00');
        expect(googleWrites())->toHaveCount(0);
    });

    it('copies an all-day event, keeping its last day rather than Google\'s exclusive end', function () {
        fakeGoogle(single: ['ev2' => allDayEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['event_id' => 'ev2', 'type' => 'event']))->assertSessionHasNoErrors();

        $event = CalendarEvent::firstOrFail();
        expect($event->all_day)->toBeTrue()->and($event->starts_on->toDateString())->toBe('2026-10-08')->and($event->ends_on->toDateString())->toBe('2026-10-09')->and($event->starts_at)->toBeNull();
    });

    it('is not copied twice, and then no longer shows in the preview', function () {
        fakeGoogle(events: [timedEvent()], single: ['ev1' => timedEvent()]);
        $user = importer();

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['type' => 'event']));
        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['type' => 'event']))->assertSessionHasErrors('type');

        expect(CalendarEvent::count())->toBe(1)->and(overlayTitles($user))->toBe([]);
    });

    it('can go in a responsibility of the user\'s', function () {
        fakeGoogle(single: ['ev1' => timedEvent()]);
        $user = importer();
        $responsibility = $user->responsibilities()->create(['name' => 'Health']);

        $this->actingAs($user)->post('/integrations/google/imports', importPayload(['type' => 'event', 'responsibility_id' => $responsibility->id]));

        expect(CalendarEvent::firstOrFail()->responsibility_id)->toBe($responsibility->id);
    });
});

describe('moving the whole calendar over', function () {
    it('turns every upcoming event into the app\'s own event', function () {
        fakeGoogle(events: [timedEvent(['id' => 'a', 'summary' => 'Dentist']), allDayEvent(['id' => 'b', 'summary' => 'Conference']), timedEvent(['id' => 'c', 'summary' => 'Call', 'location' => 'Zoom'])]);
        $user = importer();

        migrate($user)->assertSessionHas('status', 'Moved 3 events and 0 routines into your planner. The Google preview is now off.');

        expect(CalendarEvent::where('user_id', $user->id)->orderBy('id')->pluck('title')->all())->toBe(['Dentist', 'Conference', 'Call'])
            ->and(CalendarEvent::where('title', 'Conference')->first()->all_day)->toBeTrue()
            ->and(CalendarEvent::where('title', 'Call')->first()->location)->toBe('Zoom');
    });

    it('turns a repeating series into one routine instead of many events', function () {
        fakeGoogle(events: [seriesInstance(), seriesInstance(['id' => 'series1_20261015T120000Z'])], single: ['series1' => seriesMaster()]);
        $user = importer();

        migrate($user)->assertSessionHas('status', 'Moved 0 events and 1 routine into your planner. The Google preview is now off.');

        $routine = Routine::firstOrFail();
        expect($routine->title)->toBe('Prepare breakfast')->and($routine->days)->toBe([2, 4])->and(CalendarEvent::count())->toBe(0);
    });

    it('brings a series routines cannot follow in as single events, and says so', function () {
        fakeGoogle(
            events: [seriesInstance(['summary' => 'Book club']), seriesInstance(['id' => 'series1_20261015T120000Z', 'summary' => 'Book club'])],
            single: ['series1' => seriesMaster(['summary' => 'Book club', 'recurrence' => ['RRULE:FREQ=MONTHLY;BYDAY=1TU']])],
        );
        $user = importer();

        $response = migrate($user);

        expect(CalendarEvent::pluck('title')->all())->toBe(['Book club', 'Book club'])->and(Routine::count())->toBe(0);
        $response->assertSessionHas('migrationNotes', fn (array $notes) => count($notes) === 1 && str_contains($notes[0], 'Book club') && str_contains($notes[0], 'single events'));
    });

    it('leaves out what is cancelled, was added by an older version of this app, is hidden, or is already in', function () {
        fakeGoogle(events: [
            timedEvent(['id' => 'good', 'summary' => 'Keep']),
            timedEvent(['id' => 'cancelled', 'summary' => 'Cancelled', 'status' => 'cancelled']),
            timedEvent(['id' => 'ours', 'summary' => 'Pushed earlier', 'extendedProperties' => ['private' => ['planner' => 'session:1']]]),
            timedEvent(['id' => 'hidden', 'summary' => 'Hidden']),
            timedEvent(['id' => 'have', 'summary' => 'Have it']),
        ]);
        $user = importer();
        $user->googleEventImports()->create(['google_calendar_id' => 'primary-id', 'google_event_id' => 'hidden', 'kind' => 'hidden', 'item_id' => 0]);
        $user->googleEventImports()->create(['google_calendar_id' => 'primary-id', 'google_event_id' => 'have', 'kind' => 'task', 'item_id' => 1]);

        migrate($user);

        expect(CalendarEvent::pluck('title')->all())->toBe(['Keep']);
    });

    it('switches the Google preview off once it is done', function () {
        fakeGoogle(events: [timedEvent()]);
        $user = importer();

        migrate($user);

        expect($user->googleAccount->fresh()->import_calendar_ids)->toBe([]);
        $this->actingAs($user)->get('/?week=2026-10-05&timezone=America/New_York')->assertInertia(fn ($page) => $page->where('googleEvents', []));
    });

    it('only brings in what is new when run again', function () {
        fakeGoogle(events: [timedEvent(['id' => 'a'])]);
        $user = importer();
        migrate($user);
        $user->googleAccount->update(['import_calendar_ids' => ['primary-id']]);
        GoogleFake::$events = [timedEvent(['id' => 'a']), timedEvent(['id' => 'b', 'summary' => 'New one'])];

        migrate($user)->assertSessionHas('status', 'Moved 1 event and 0 routines into your planner. The Google preview is now off.');

        expect(CalendarEvent::count())->toBe(2);
    });

    it('never writes anything to Google', function () {
        fakeGoogle(events: [timedEvent()]);

        migrate(importer());

        expect(googleWrites())->toHaveCount(0);
    });

    it('moves nothing and keeps the preview on when Google fails part way, and says so', function () {
        fakeGoogle(['GET /events' => 500]);
        $user = importer();

        migrate($user)->assertSessionHasErrors('migration');

        expect(CalendarEvent::count())->toBe(0)->and($user->googleAccount->fresh()->import_calendar_ids)->toBe(['primary-id']);
    });

    it('needs calendars to move over', function () {
        fakeGoogle();
        $user = googleUser(['import_calendar_ids' => []]);

        migrate($user)->assertSessionHasErrors('migration');
    });

    it('needs a Google connection that works', function () {
        fakeGoogle();

        migrate(googleUser(['needs_reconnect' => true]))->assertSessionHasErrors('migration');
        $this->actingAs(User::factory()->create())->post('/integrations/google/migrate', ['timezone' => 'UTC'])->assertNotFound();
    });

    it('is only for people who are logged in, and needs a timezone', function () {
        auth()->logout();
        $this->post('/integrations/google/migrate', ['timezone' => 'UTC'])->assertRedirect('/login');

        migrate(importer(), ['timezone' => 'Mars/Base'])->assertSessionHasErrors('timezone');
        expect(GoogleAccount::count())->toBe(1);
    });

    it('only touches the user\'s own account', function () {
        fakeGoogle(events: [timedEvent()]);
        $other = importer();
        $user = importer();

        migrate($user);

        expect(CalendarEvent::where('user_id', $other->id)->count())->toBe(0)->and($other->googleAccount->fresh()->import_calendar_ids)->toBe(['primary-id']);
    });
});
