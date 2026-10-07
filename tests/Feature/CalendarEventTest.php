<?php

use App\Models\CalendarEvent;
use App\Models\GoogleEventImport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Wednesday 2026-10-07, 2 PM in New York.
    Carbon::setTestNow('2026-10-07 18:00:00 UTC');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function timedChange(array $overrides = []): array
{
    return array_merge([
        'title' => 'Dentist',
        'location' => 'Main St clinic',
        'notes' => 'Bring the form',
        'responsibility_id' => null,
        'all_day' => false,
        'starts_at' => '2026-10-08T10:00:00-04:00',
        'ends_at' => '2026-10-08T11:00:00-04:00',
    ], $overrides);
}

it('requires a login', function () {
    auth()->logout();

    $this->patchJson('/events/1', timedChange())->assertUnauthorized();
    $this->deleteJson('/events/1')->assertUnauthorized();
});

describe('changing an event', function () {
    it('changes its details and time', function () {
        $event = CalendarEvent::factory()->for($this->user)->create(['title' => 'Old', 'notes' => 'Old notes']);
        $responsibility = $this->user->responsibilities()->create(['name' => 'Health']);

        $this->patch("/events/{$event->id}", timedChange(['responsibility_id' => $responsibility->id]))->assertRedirect();

        $event->refresh();
        expect($event->title)->toBe('Dentist')->and($event->location)->toBe('Main St clinic')->and($event->notes)->toBe('Bring the form')->and($event->responsibility_id)->toBe($responsibility->id)
            ->and($event->starts_at->toIso8601String())->toBe('2026-10-08T14:00:00+00:00')->and($event->ends_at->toIso8601String())->toBe('2026-10-08T15:00:00+00:00');
    });

    it('clears a place or notes that are emptied', function () {
        $event = CalendarEvent::factory()->for($this->user)->create(['location' => 'Somewhere', 'notes' => 'Something']);

        $this->patch("/events/{$event->id}", timedChange(['location' => '  ', 'notes' => '']));

        expect($event->fresh()->location)->toBeNull()->and($event->fresh()->notes)->toBeNull();
    });

    it('moves an all-day event by its days', function () {
        $event = CalendarEvent::factory()->for($this->user)->allDay('2026-10-08', '2026-10-09')->create();

        $this->patch("/events/{$event->id}", ['title' => 'Conference', 'all_day' => true, 'start_date' => '2026-10-12', 'end_date' => '2026-10-14']);

        $event->refresh();
        expect($event->starts_on->toDateString())->toBe('2026-10-12')->and($event->ends_on->toDateString())->toBe('2026-10-14')->and($event->starts_at)->toBeNull();
    });

    it('does not turn a timed event into an all-day one, or the reverse', function () {
        $timed = CalendarEvent::factory()->for($this->user)->create();
        $allDay = CalendarEvent::factory()->for($this->user)->allDay()->create();

        $this->patchJson("/events/{$timed->id}", ['title' => 'X', 'all_day' => true, 'start_date' => '2026-10-12', 'end_date' => '2026-10-12'])->assertJsonValidationErrors('all_day');
        $this->patchJson("/events/{$allDay->id}", timedChange())->assertJsonValidationErrors('all_day');
    });

    it('is validated', function (array $change, string $field) {
        $event = CalendarEvent::factory()->for($this->user)->create();

        $this->patchJson("/events/{$event->id}", timedChange($change))->assertJsonValidationErrors($field);
    })->with([
        'no title' => [['title' => ' '], 'title'],
        'a title that is too long' => [['title' => str_repeat('a', 256)], 'title'],
        'ends before it starts' => [['ends_at' => '2026-10-08T09:00:00-04:00'], 'ends_at'],
        'no offset on a time' => [['starts_at' => '2026-10-08T10:00:00'], 'starts_at'],
        'notes that are too long' => [['notes' => str_repeat('a', 5001)], 'notes'],
        'someone else\'s responsibility' => [['responsibility_id' => 99999], 'responsibility_id'],
    ]);

    it('rejects an all-day event whose last day is before its first', function () {
        $event = CalendarEvent::factory()->for($this->user)->allDay()->create();

        $this->patchJson("/events/{$event->id}", ['title' => 'X', 'all_day' => true, 'start_date' => '2026-10-12', 'end_date' => '2026-10-10'])->assertJsonValidationErrors('end_date');
    });

    it('is only possible for your own events', function () {
        $theirs = CalendarEvent::factory()->for(User::factory())->create(['title' => 'Theirs']);

        $this->patchJson("/events/{$theirs->id}", timedChange())->assertNotFound();

        expect($theirs->fresh()->title)->toBe('Theirs');
    });
});

describe('deleting an event', function () {
    it('removes it', function () {
        $event = CalendarEvent::factory()->for($this->user)->create();

        $this->delete("/events/{$event->id}")->assertRedirect();

        expect(CalendarEvent::count())->toBe(0);
    });

    it('is only possible for your own events', function () {
        $theirs = CalendarEvent::factory()->for(User::factory())->create();

        $this->deleteJson("/events/{$theirs->id}")->assertNotFound();

        expect(CalendarEvent::count())->toBe(1);
    });

    it('lets a Google event it was copied from show in the preview again', function () {
        $event = CalendarEvent::factory()->for($this->user)->create();
        $this->user->googleEventImports()->create(['google_calendar_id' => 'primary-id', 'google_event_id' => 'ev1', 'kind' => 'event', 'item_id' => $event->id]);

        $event->delete();

        expect(GoogleEventImport::count())->toBe(0);
    });

    it('is removed with its owner', function () {
        CalendarEvent::factory()->for($this->user)->create();

        $this->user->delete();

        expect(CalendarEvent::count())->toBe(0);
    });

    it('survives deleting the responsibility it belonged to', function () {
        $responsibility = $this->user->responsibilities()->create(['name' => 'Health']);
        $event = CalendarEvent::factory()->for($this->user)->create(['responsibility_id' => $responsibility->id]);

        $responsibility->delete();

        expect($event->fresh()->responsibility_id)->toBeNull();
    });
});

describe('the calendar', function () {
    it('gets the events of the visible week and of today, and only the user\'s own', function () {
        CalendarEvent::factory()->for($this->user)->create(['title' => 'Today', 'starts_at' => '2026-10-07 19:00:00', 'ends_at' => '2026-10-07 20:00:00']);
        CalendarEvent::factory()->for($this->user)->create(['title' => 'Friday', 'starts_at' => '2026-10-09 19:00:00', 'ends_at' => '2026-10-09 20:00:00']);
        CalendarEvent::factory()->for($this->user)->create(['title' => 'Next week', 'starts_at' => '2026-10-14 19:00:00', 'ends_at' => '2026-10-14 20:00:00']);
        CalendarEvent::factory()->for(User::factory())->create(['title' => 'Not mine']);

        $this->get('/?timezone=America/New_York')->assertInertia(fn (Assert $page) => $page
            ->where('events', fn ($events) => $events->pluck('title')->all() === ['Today', 'Friday'])
            ->where('todayEvents', fn ($events) => $events->pluck('title')->all() === ['Today']));
    });

    it('shows next week\'s events and today\'s when the calendar is on another week', function () {
        CalendarEvent::factory()->for($this->user)->create(['title' => 'Today', 'starts_at' => '2026-10-07 19:00:00', 'ends_at' => '2026-10-07 20:00:00']);
        CalendarEvent::factory()->for($this->user)->create(['title' => 'Next week', 'starts_at' => '2026-10-14 19:00:00', 'ends_at' => '2026-10-14 20:00:00']);

        $this->get('/?week=2026-10-12&timezone=America/New_York')->assertInertia(fn (Assert $page) => $page
            ->where('events', fn ($events) => $events->pluck('title')->all() === ['Next week'])
            ->where('todayEvents', fn ($events) => $events->pluck('title')->all() === ['Today']));
    });

    it('includes an all-day event that starts before the week and runs into it, in the calendar\'s timezone', function () {
        CalendarEvent::factory()->for($this->user)->allDay('2026-10-03', '2026-10-05')->create(['title' => 'Trip']);
        CalendarEvent::factory()->for($this->user)->allDay('2026-10-03', '2026-10-04')->create(['title' => 'Over before']);
        CalendarEvent::factory()->for($this->user)->allDay('2026-10-11', '2026-10-12')->create(['title' => 'Sunday on']);

        $this->get('/?timezone=America/New_York')->assertInertia(fn (Assert $page) => $page
            ->where('events', fn ($events) => $events->pluck('title')->sort()->values()->all() === ['Sunday on', 'Trip'])
            ->where('events.0.all_day', true)
            ->where('events.0.start_date', '2026-10-03'));
    });

    it('uses the calendar\'s timezone to decide which day a timed event is on', function () {
        // 03:00 UTC on Monday the 12th is Sunday evening in New York, so still the week of the 5th there.
        CalendarEvent::factory()->for($this->user)->create(['title' => 'Late Sunday', 'starts_at' => '2026-10-12 03:00:00', 'ends_at' => '2026-10-12 04:00:00']);

        $this->get('/?timezone=America/New_York')->assertInertia(fn (Assert $page) => $page->has('events', 1));
        $this->get('/?timezone=Europe/Istanbul')->assertInertia(fn (Assert $page) => $page->has('events', 0));
    });
});
