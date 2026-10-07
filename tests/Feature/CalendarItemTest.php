<?php

use App\Models\CalendarEvent;
use App\Models\CalendarSession;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/** 2026-10-07, 3 PM to 4:30 PM in New York (19:00 to 20:30 UTC). */
function dragged(array $overrides = []): array
{
    return array_merge([
        'type' => 'task',
        'title' => 'Write the essay',
        'starts_at' => '2026-10-07T15:00:00-04:00',
        'ends_at' => '2026-10-07T16:30:00-04:00',
        'timezone' => 'America/New_York',
        'reserve' => true,
    ], $overrides);
}

it('requires a login', function () {
    auth()->logout();

    $this->postJson('/calendar/items', dragged())->assertUnauthorized();
});

describe('a task', function () {
    it('is added with the dragged time reserved as a work session', function () {
        $this->post('/calendar/items', dragged())->assertRedirect()->assertSessionHas('status', 'Task added with time reserved.');

        $task = Task::firstOrFail();
        $session = CalendarSession::firstOrFail();
        expect($task->title)->toBe('Write the essay')->and($task->user_id)->toBe($this->user->id)->and($task->due_at)->toBeNull()->and($task->completed_at)->toBeNull()
            ->and($session->task_id)->toBe($task->id)->and($session->user_id)->toBe($this->user->id)
            ->and($session->starts_at->toIso8601String())->toBe('2026-10-07T19:00:00+00:00')->and($session->ends_at->toIso8601String())->toBe('2026-10-07T20:30:00+00:00');
    });

    it('can be added without reserving the time', function () {
        $this->post('/calendar/items', dragged(['reserve' => false]))->assertSessionHas('status', 'Task added.');

        expect(Task::count())->toBe(1)->and(CalendarSession::count())->toBe(0);
    });

    it('can take the end of the dragged time as its deadline', function () {
        $this->post('/calendar/items', dragged(['deadline_at_end' => true]));

        $task = Task::firstOrFail();
        expect($task->due_has_time)->toBeTrue()->and($task->due_at->toIso8601String())->toBe('2026-10-07T20:30:00+00:00');
    });

    it('goes in the chosen responsibility', function () {
        $responsibility = $this->user->responsibilities()->create(['name' => 'School']);

        $this->post('/calendar/items', dragged(['responsibility_id' => $responsibility->id]));

        expect(Task::firstOrFail()->responsibility_id)->toBe($responsibility->id);
    });

    it('refuses another user\'s or an archived responsibility', function () {
        $theirs = User::factory()->create()->responsibilities()->create(['name' => 'Theirs']);

        $this->postJson('/calendar/items', dragged(['responsibility_id' => $theirs->id]))->assertJsonValidationErrors('responsibility_id');

        expect(Task::count())->toBe(0);
    });

    it('is not added when the reserved time overlaps a session, until the user allows it', function () {
        plannedSession($this->user, null, '2026-10-07 19:30:00');

        $this->postJson('/calendar/items', dragged())->assertJsonValidationErrors('allow_overlap');
        expect(Task::count())->toBe(1)->and(CalendarSession::count())->toBe(1);

        $this->postJson('/calendar/items', dragged(['allow_overlap' => true]))->assertRedirect();
        expect(Task::count())->toBe(2)->and(CalendarSession::count())->toBe(2);
    });

    it('does not mind an overlap when no time is reserved', function () {
        plannedSession($this->user, null, '2026-10-07 19:30:00');

        $this->post('/calendar/items', dragged(['reserve' => false]))->assertRedirect()->assertSessionHasNoErrors();

        expect(Task::where('title', 'Write the essay')->exists())->toBeTrue();
    });

    it('needs to say whether time is reserved', function () {
        $payload = dragged();
        unset($payload['reserve']);

        $this->postJson('/calendar/items', $payload)->assertJsonValidationErrors('reserve');
    });
});

describe('the dragged range', function () {
    it('must have a title, a valid type and an end after the start', function (array $change, string $field) {
        $this->postJson('/calendar/items', dragged($change))->assertJsonValidationErrors($field);

        expect(Task::count())->toBe(0);
    })->with([
        'no title' => [['title' => ' '], 'title'],
        'unknown type' => [['type' => 'meeting'], 'type'],
        'ends before it starts' => [['ends_at' => '2026-10-07T14:00:00-04:00'], 'ends_at'],
        'ends when it starts' => [['ends_at' => '2026-10-07T15:00:00-04:00'], 'ends_at'],
        'no offset on the time' => [['starts_at' => '2026-10-07T15:00:00'], 'starts_at'],
        'bad timezone' => [['timezone' => 'Mars/Base'], 'timezone'],
    ]);

    it('cannot be longer than a day', function () {
        $this->postJson('/calendar/items', dragged(['ends_at' => '2026-10-08T16:00:00-04:00']))->assertJsonValidationErrors('ends_at');
    });
});

describe('an event', function () {
    it('is added as the app\'s own event at the dragged time, and nothing is sent anywhere', function () {
        fakeGoogle();

        $this->post('/calendar/items', dragged(['type' => 'event', 'title' => 'Dentist', 'location' => ' Main St clinic ', 'reserve' => null]))->assertRedirect()->assertSessionHas('status', 'Event added.');

        $event = CalendarEvent::firstOrFail();
        expect($event->user_id)->toBe($this->user->id)->and($event->title)->toBe('Dentist')->and($event->location)->toBe('Main St clinic')->and($event->all_day)->toBeFalse()
            ->and($event->starts_at->toIso8601String())->toBe('2026-10-07T19:00:00+00:00')->and($event->ends_at->toIso8601String())->toBe('2026-10-07T20:30:00+00:00')
            ->and($event->responsibility_id)->toBeNull();
        expect(Task::count())->toBe(0)->and(CalendarSession::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('works without Google, and without a place', function () {
        $this->post('/calendar/items', dragged(['type' => 'event', 'reserve' => null]))->assertSessionHasNoErrors();

        expect(CalendarEvent::firstOrFail()->location)->toBeNull();
    });

    it('goes in the chosen responsibility', function () {
        $responsibility = $this->user->responsibilities()->create(['name' => 'School']);

        $this->post('/calendar/items', dragged(['type' => 'event', 'reserve' => null, 'responsibility_id' => $responsibility->id]));

        expect(CalendarEvent::firstOrFail()->responsibility_id)->toBe($responsibility->id);
    });

    it('may overlap anything, since it is just time that is taken', function () {
        plannedSession($this->user, null, '2026-10-07 19:30:00');

        $this->post('/calendar/items', dragged(['type' => 'event', 'reserve' => null]))->assertSessionHasNoErrors();

        expect(CalendarEvent::count())->toBe(1);
    });

    it('refuses a place that is too long', function () {
        $this->postJson('/calendar/items', dragged(['type' => 'event', 'reserve' => null, 'location' => str_repeat('a', 256)]))->assertJsonValidationErrors('location');
    });
});

describe('a routine', function () {
    it('repeats on the chosen weekdays at the dragged time and length', function () {
        $this->post('/calendar/items', dragged(['type' => 'routine', 'title' => 'Gym', 'days' => [5, 1, 3], 'reserve' => null, 'ends_on' => '2026-12-18']))->assertSessionHas('status', 'Routine added.');

        $routine = Routine::firstOrFail();
        expect($routine->user_id)->toBe($this->user->id)->and($routine->title)->toBe('Gym')->and($routine->days)->toBe([1, 3, 5])->and($routine->start_time)->toBe('15:00:00')
            ->and($routine->duration_minutes)->toBe(90)->and($routine->timezone)->toBe('America/New_York')->and($routine->starts_on->toDateString())->toBe('2026-10-07')->and($routine->ends_on->toDateString())->toBe('2026-12-18');
        expect(Task::count())->toBe(0)->and(CalendarSession::count())->toBe(0);
    });

    it('keeps the start date and time in the routine\'s own timezone', function () {
        // 02:30 UTC on the 8th is 10:30 PM on the 7th in New York.
        $this->post('/calendar/items', dragged(['type' => 'routine', 'days' => [3], 'reserve' => null, 'starts_at' => '2026-10-08T02:30:00Z', 'ends_at' => '2026-10-08T03:30:00Z']));

        $routine = Routine::firstOrFail();
        expect($routine->start_time)->toBe('22:30:00')->and($routine->starts_on->toDateString())->toBe('2026-10-07');
    });

    it('needs at least one day', function (array $days) {
        $this->postJson('/calendar/items', dragged(['type' => 'routine', 'days' => $days, 'reserve' => null]))->assertJsonValidationErrors($days === [] ? 'days' : 'days.0');
    })->with([[[]], [[0]], [[8]]]);

    it('needs five minutes or more, and an end date that is not before the first day', function () {
        $this->postJson('/calendar/items', dragged(['type' => 'routine', 'days' => [1], 'reserve' => null, 'ends_at' => '2026-10-07T15:03:00-04:00']))->assertJsonValidationErrors('ends_at');
        $this->postJson('/calendar/items', dragged(['type' => 'routine', 'days' => [1], 'reserve' => null, 'ends_on' => '2026-10-01']))->assertJsonValidationErrors('ends_on');

        expect(Routine::count())->toBe(0);
    });
});
