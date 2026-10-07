<?php

use App\Models\PomodoroSession;
use App\Models\PomodoroSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    // Wednesday 2026-10-07, 2 PM in New York.
    Carbon::setTestNow('2026-10-07 18:00:00 UTC');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function startTimer(string $kind = 'focus', ?int $taskId = null)
{
    return test()->postJson('/pomodoro', ['kind' => $kind, 'task_id' => $taskId]);
}

function activeId(): int
{
    return PomodoroSession::firstOrFail()->id;
}

it('requires a login for every timer action', function () {
    auth()->logout();

    $this->getJson('/pomodoro')->assertUnauthorized();
    $this->postJson('/pomodoro', ['kind' => 'focus'])->assertUnauthorized();
    $this->get('/focus')->assertRedirect('/login');
});

it('starts with the deep work rhythm, and no timer', function () {
    $this->getJson('/pomodoro')->assertOk()
        ->assertJsonPath('settings', ['focus_minutes' => 50, 'short_break_minutes' => 10, 'long_break_minutes' => 30, 'rounds_before_long' => 3, 'sound_enabled' => true])
        ->assertJsonPath('active', null)
        ->assertJsonPath('cycle', ['done' => 0, 'of' => 3, 'next_break' => 'short_break']);
});

it('starts a focus round of the chosen length', function () {
    startTimer()->assertOk()->assertJsonPath('active.kind', 'focus')->assertJsonPath('active.status', 'running')->assertJsonPath('active.planned_seconds', 3000)->assertJsonPath('active.remaining_seconds', 3000);
});

it('links a focus round to an open task of the user', function () {
    $task = $this->user->tasks()->create(['title' => 'Write essay', 'priority' => 'normal']);

    startTimer('focus', $task->id)->assertOk()->assertJsonPath('active.task_id', $task->id)->assertJsonPath('active.task_title', 'Write essay');
});

it('refuses a task that is not the user\'s, is finished, or does not exist', function () {
    $theirs = User::factory()->create()->tasks()->create(['title' => 'Theirs', 'priority' => 'normal']);
    $done = $this->user->tasks()->create(['title' => 'Done', 'priority' => 'normal']);
    $done->forceFill(['completed_at' => now()])->save();

    foreach ([$theirs->id, $done->id, 9999] as $id) {
        startTimer('focus', $id)->assertUnprocessable();
    }

    expect(PomodoroSession::count())->toBe(0);
});

it('does not link breaks to a task', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);

    startTimer('short_break', $task->id)->assertOk()->assertJsonPath('active.task_id', null)->assertJsonPath('active.planned_seconds', 600);
});

it('uses the long break length for a long break', function () {
    startTimer('long_break')->assertOk()->assertJsonPath('active.planned_seconds', 1800);
});

it('refuses an unknown kind, and a second timer while one is running', function () {
    startTimer('nap')->assertUnprocessable();

    startTimer()->assertOk();
    startTimer('short_break')->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'already running'));

    expect(PomodoroSession::count())->toBe(1);
});

it('counts the time down from the stored start, so a refresh loses nothing', function () {
    startTimer();

    $this->travel(20)->minutes();

    $this->getJson('/pomodoro')->assertJsonPath('active.remaining_seconds', 1800);
});

it('stops the clock while paused and carries on when resumed', function () {
    startTimer();
    $id = activeId();
    $this->travel(10)->minutes();
    $this->postJson("/pomodoro/{$id}/pause")->assertOk()->assertJsonPath('active.status', 'paused')->assertJsonPath('active.remaining_seconds', 2400);

    $this->travel(30)->minutes();
    $this->getJson('/pomodoro')->assertJsonPath('active.remaining_seconds', 2400);

    $this->postJson("/pomodoro/{$id}/resume")->assertOk()->assertJsonPath('active.status', 'running')->assertJsonPath('active.remaining_seconds', 2400);
    $this->travel(5)->minutes();
    $this->getJson('/pomodoro')->assertJsonPath('active.remaining_seconds', 2100);
});

it('cannot pause what is not running, or resume what is not paused', function () {
    startTimer();
    $id = activeId();

    $this->postJson("/pomodoro/{$id}/resume")->assertUnprocessable();
    $this->postJson("/pomodoro/{$id}/pause")->assertOk();
    $this->postJson("/pomodoro/{$id}/pause")->assertUnprocessable();
});

it('does not count a round that is not finished', function () {
    startTimer();
    $this->travel(30)->minutes();

    $this->postJson('/pomodoro/'.activeId().'/complete')->assertUnprocessable()->assertJsonPath('message', 'This round is not finished yet.');

    expect(PomodoroSession::firstOrFail()->status)->toBe('running');
});

it('counts a finished round, forgiving a couple of seconds of clock drift', function () {
    startTimer();
    $this->travelTo(now()->addMinutes(50)->subSeconds(2));

    $this->postJson('/pomodoro/'.activeId().'/complete')->assertOk()->assertJsonPath('active', null)->assertJsonPath('cycle.done', 1);

    expect(PomodoroSession::firstOrFail()->status)->toBe('completed');
});

it('records when the round really ended if the tab was closed', function () {
    startTimer();
    $this->travel(3)->hours();

    $this->postJson('/pomodoro/'.activeId().'/complete')->assertOk();

    expect(PomodoroSession::firstOrFail()->ended_at->toIso8601String())->toBe('2026-10-07T18:50:00+00:00');
});

it('allows a paused round to be completed only if its time was used', function () {
    startTimer();
    $id = activeId();
    $this->travel(20)->minutes();
    $this->postJson("/pomodoro/{$id}/pause");
    $this->travel(2)->hours();

    $this->postJson("/pomodoro/{$id}/complete")->assertUnprocessable();
});

it('does not count a round that was stopped early', function () {
    startTimer();

    $this->postJson('/pomodoro/'.activeId().'/abandon')->assertOk()->assertJsonPath('active', null)->assertJsonPath('cycle.done', 0);

    expect(PomodoroSession::firstOrFail()->status)->toBe('abandoned');
    $this->postJson('/pomodoro/'.activeId().'/complete')->assertUnprocessable();
    $this->getJson('/pomodoro/stats?timezone=UTC')->assertJsonPath('today.rounds', 0);
});

it('cannot touch another user\'s timer', function () {
    $other = PomodoroSession::factory()->for(User::factory())->create();

    foreach (['pause', 'resume', 'complete', 'abandon'] as $action) {
        $this->postJson("/pomodoro/{$other->id}/{$action}")->assertNotFound();
    }

    expect($other->fresh()->status)->toBe('running');
});

it('gives up on a timer that was left running for a day', function () {
    startTimer();
    $this->travel(25)->hours();

    $this->getJson('/pomodoro')->assertJsonPath('active', null);
    startTimer()->assertOk();

    expect(PomodoroSession::orderBy('id')->first()->status)->toBe('abandoned');
});

it('suggests a long break after the set number of rounds, and starts the set again after it', function () {
    foreach ([1, 2] as $_) {
        PomodoroSession::factory()->for($this->user)->completed(now()->subMinutes(30))->create();
    }
    $this->getJson('/pomodoro')->assertJsonPath('cycle', ['done' => 2, 'of' => 3, 'next_break' => 'short_break']);

    PomodoroSession::factory()->for($this->user)->completed(now()->subMinutes(10))->create();
    $this->getJson('/pomodoro')->assertJsonPath('cycle.done', 3)->assertJsonPath('cycle.next_break', 'long_break');

    PomodoroSession::factory()->for($this->user)->completed(now()->subMinutes(1))->create(['kind' => 'long_break']);
    $this->getJson('/pomodoro')->assertJsonPath('cycle', ['done' => 0, 'of' => 3, 'next_break' => 'short_break']);
});

it('ignores rounds from long ago when working out the current set', function () {
    PomodoroSession::factory()->for($this->user)->completed(now()->subHours(13))->create();
    PomodoroSession::factory()->for($this->user)->completed(now()->subHours(1))->create();

    $this->getJson('/pomodoro')->assertJsonPath('cycle.done', 1);
});

it('keeps the set to the user\'s own rounds', function () {
    PomodoroSession::factory()->for(User::factory())->completed(now()->subMinutes(5))->create();

    $this->getJson('/pomodoro')->assertJsonPath('cycle.done', 0);
});

it('saves a new rhythm, and the next round uses it', function () {
    $this->patchJson('/pomodoro/settings', ['focus_minutes' => 25, 'short_break_minutes' => 5, 'long_break_minutes' => 15, 'rounds_before_long' => 4, 'sound_enabled' => false])
        ->assertOk()->assertJsonPath('settings.focus_minutes', 25)->assertJsonPath('settings.sound_enabled', false)->assertJsonPath('cycle.of', 4);

    startTimer()->assertJsonPath('active.planned_seconds', 1500);
    expect(PomodoroSetting::count())->toBe(1);

    $this->patchJson('/pomodoro/settings', ['focus_minutes' => 30, 'short_break_minutes' => 5, 'long_break_minutes' => 15, 'rounds_before_long' => 4, 'sound_enabled' => true]);
    expect(PomodoroSetting::count())->toBe(1);
});

it('does not let a running round change length when the rhythm changes', function () {
    startTimer();

    $this->patchJson('/pomodoro/settings', ['focus_minutes' => 25, 'short_break_minutes' => 5, 'long_break_minutes' => 15, 'rounds_before_long' => 4, 'sound_enabled' => true]);

    $this->getJson('/pomodoro')->assertJsonPath('active.planned_seconds', 3000);
});

it('validates the rhythm', function (array $change) {
    $valid = ['focus_minutes' => 50, 'short_break_minutes' => 10, 'long_break_minutes' => 30, 'rounds_before_long' => 3, 'sound_enabled' => true];

    $this->patchJson('/pomodoro/settings', [...$valid, ...$change])->assertUnprocessable();
})->with([
    'focus too short' => [['focus_minutes' => 4]],
    'focus too long' => [['focus_minutes' => 181]],
    'no break' => [['short_break_minutes' => 0]],
    'long break too long' => [['long_break_minutes' => 121]],
    'one round' => [['rounds_before_long' => 1]],
    'too many rounds' => [['rounds_before_long' => 9]],
    'not a number' => [['focus_minutes' => 'lots']],
]);

it('counts today\'s rounds and minutes in the user\'s own timezone', function () {
    // 03:00 UTC on the 8th is 11 PM on the 7th in New York, but already tomorrow in Istanbul.
    PomodoroSession::factory()->for($this->user)->completed(Carbon::parse('2026-10-08 03:00:00 UTC'))->create();
    PomodoroSession::factory()->for($this->user)->completed(Carbon::parse('2026-10-07 15:00:00 UTC'))->create();

    $this->getJson('/pomodoro/stats?timezone=America/New_York')->assertJsonPath('today', ['date' => '2026-10-07', 'rounds' => 2, 'minutes' => 100]);
});

it('draws the last seven days, oldest first', function () {
    PomodoroSession::factory()->for($this->user)->completed(Carbon::parse('2026-10-05 16:00:00 UTC'))->create();
    PomodoroSession::factory()->for($this->user)->completed(Carbon::parse('2026-10-01 16:00:00 UTC'))->create();

    $week = $this->getJson('/pomodoro/stats?timezone=America/New_York')->json('week');

    expect($week)->toHaveCount(7)->and($week[0]['date'])->toBe('2026-10-01')->and($week[0]['rounds'])->toBe(1)->and($week[4]['date'])->toBe('2026-10-05')->and($week[4]['minutes'])->toBe(50)->and($week[6]['date'])->toBe('2026-10-07');
});

it('counts the streak of days with a finished round', function () {
    foreach (['2026-10-07', '2026-10-06', '2026-10-05', '2026-10-03'] as $day) {
        PomodoroSession::factory()->for($this->user)->completed(Carbon::parse("{$day} 16:00:00 UTC"))->create();
    }

    $this->getJson('/pomodoro/stats?timezone=America/New_York')->assertJsonPath('streak', 3);
});

it('keeps the streak alive until a round is done today', function () {
    foreach (['2026-10-06', '2026-10-05'] as $day) {
        PomodoroSession::factory()->for($this->user)->completed(Carbon::parse("{$day} 16:00:00 UTC"))->create();
    }

    $this->getJson('/pomodoro/stats?timezone=America/New_York')->assertJsonPath('streak', 2)->assertJsonPath('today.rounds', 0);
});

it('starts a streak at nothing', function () {
    $this->getJson('/pomodoro/stats?timezone=America/New_York')->assertJsonPath('streak', 0)->assertJsonPath('total_rounds', 0)->assertJsonPath('recent', []);
});

it('counts only finished focus rounds of this user in the stats', function () {
    PomodoroSession::factory()->for($this->user)->completed()->create(['kind' => 'short_break', 'planned_seconds' => 600]);
    PomodoroSession::factory()->for($this->user)->create(['status' => 'abandoned', 'ended_at' => now()]);
    PomodoroSession::factory()->for(User::factory())->completed()->create();
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);
    PomodoroSession::factory()->for($this->user)->completed()->create(['task_id' => $task->id]);

    $stats = $this->getJson('/pomodoro/stats?timezone=UTC')->assertJsonPath('total_rounds', 1)->assertJsonPath('today.rounds', 1)->json();

    expect($stats['recent'])->toHaveCount(1)->and($stats['recent'][0]['task_title'])->toBe('Essay')->and($stats['recent'][0]['minutes'])->toBe(50);
});

it('keeps the history of a round when its task is deleted', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);
    PomodoroSession::factory()->for($this->user)->completed()->create(['task_id' => $task->id]);

    $task->delete();

    $this->getJson('/pomodoro/stats?timezone=UTC')->assertJsonPath('total_rounds', 1)->assertJsonPath('recent.0.task_title', null);
});

it('needs a valid timezone for the stats', function () {
    $this->getJson('/pomodoro/stats?timezone=Mars/Base')->assertUnprocessable();
    $this->getJson('/pomodoro/stats')->assertUnprocessable();
});

it('offers the Focus page the user\'s open tasks, and a task chosen from a link', function () {
    $open = $this->user->tasks()->create(['title' => 'Open one', 'priority' => 'normal']);
    $done = $this->user->tasks()->create(['title' => 'Done one', 'priority' => 'normal']);
    $done->forceFill(['completed_at' => now()])->save();
    User::factory()->create()->tasks()->create(['title' => 'Not mine', 'priority' => 'normal']);

    $this->get("/focus?task={$open->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('focus/index')->has('tasks', 1)->where('tasks.0.title', 'Open one')->where('initialTaskId', $open->id));
});

it('remembers the round or break that just ended, for an hour', function () {
    $this->getJson('/pomodoro')->assertJsonPath('last_completed', null);

    PomodoroSession::factory()->for($this->user)->completed(now()->subMinutes(5))->create();
    $this->getJson('/pomodoro')->assertJsonPath('last_completed.kind', 'focus');

    PomodoroSession::factory()->for($this->user)->completed(now()->subMinutes(1))->create(['kind' => 'short_break', 'planned_seconds' => 600]);
    $this->getJson('/pomodoro')->assertJsonPath('last_completed.kind', 'short_break');

    $this->travel(2)->hours();
    $this->getJson('/pomodoro')->assertJsonPath('last_completed', null);
});
