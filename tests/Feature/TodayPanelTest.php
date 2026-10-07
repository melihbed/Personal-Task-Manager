<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Wednesday 2026-10-07, 2 PM in New York.
    Carbon::setTestNow('2026-10-07 18:00:00 UTC');
});

it('sends the sessions of the real today even when the calendar shows another week', function () {
    $user = googleUser(['import_calendar_ids' => []]);
    plannedSession($user, null, '2026-10-07 19:00:00');
    plannedSession($user, null, '2026-10-14 19:00:00');

    $this->actingAs($user)->get('/?week=2026-10-12&timezone=America/New_York')
        ->assertInertia(fn (Assert $page) => $page
            ->has('sessions', 1)
            ->where('sessions.0.starts_at', '2026-10-14T19:00:00+00:00')
            ->has('todaySessions', 1)
            ->where('todaySessions.0.starts_at', '2026-10-07T19:00:00+00:00'));
});

it('sends only today\'s sessions to the panel when the calendar shows this week', function () {
    $user = googleUser(['import_calendar_ids' => []]);
    plannedSession($user, null, '2026-10-07 19:00:00');
    plannedSession($user, null, '2026-10-09 19:00:00');

    $this->actingAs($user)->get('/?timezone=America/New_York')
        ->assertInertia(fn (Assert $page) => $page->has('todaySessions', 1)->has('sessions', 2));
});

it('uses the chosen timezone to decide what today is', function () {
    $user = googleUser(['import_calendar_ids' => []]);
    // 02:00 UTC on the 8th is still the 7th in New York, but already the 8th in Istanbul.
    plannedSession($user, null, '2026-10-08 02:00:00');

    $this->actingAs($user)->get('/?timezone=America/New_York')->assertInertia(fn (Assert $page) => $page->has('todaySessions', 1));
    $this->actingAs($user)->get('/?timezone=Europe/Istanbul')->assertInertia(fn (Assert $page) => $page->has('todaySessions', 0));
});

it('loads today\'s Google events after the page appears', function () {
    $user = googleUser(['import_calendar_ids' => ['primary-id']]);
    fakeGoogle(events: [timedEvent(['id' => 'today', 'summary' => 'Dentist', 'start' => ['dateTime' => '2026-10-07T15:00:00-04:00'], 'end' => ['dateTime' => '2026-10-07T16:00:00-04:00']])]);

    $this->actingAs($user)->get('/?week=2026-10-12&timezone=America/New_York')
        ->assertInertia(fn (Assert $page) => $page->missing('googleToday')->loadDeferredProps(fn (Assert $loaded) => $loaded->where('googleToday.0.title', 'Dentist')));
});

it('sends no Google events for someone who has not connected Google', function () {
    $this->actingAs(User::factory()->create())->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('googleToday', []));
});

it('leaves today\'s Google events to the calendar\'s own when it shows the current week', function () {
    $user = googleUser(['import_calendar_ids' => ['primary-id']]);
    fakeGoogle();

    $this->actingAs($user)->get('/?timezone=America/New_York')
        ->assertInertia(fn (Assert $page) => $page->where('googleToday', null));

    expect(googleCalls('GET', '/calendars/primary-id/events'))->toHaveCount(0);
});

it('tells the dashboard when a task\'s next session starts, ignoring sessions already over', function () {
    $user = googleUser(['import_calendar_ids' => []]);
    $task = $user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);
    plannedSession($user, $task, '2026-10-07 12:00:00');
    plannedSession($user, $task, '2026-10-08 15:00:00');
    plannedSession($user, $task, '2026-10-07 22:00:00');
    $unplanned = $user->tasks()->create(['title' => 'Read', 'priority' => 'normal']);

    $this->actingAs($user)->get('/?timezone=America/New_York')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks', fn ($tasks) => collect($tasks)->firstWhere('id', $task->id)['next_session_at'] === '2026-10-07T22:00:00+00:00'
                && collect($tasks)->firstWhere('id', $unplanned->id)['next_session_at'] === null));
});
