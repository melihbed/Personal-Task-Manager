<?php

use App\Models\User;

function taskPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Write report',
        'notes' => 'Use the 2026 template.',
        'responsibility_id' => null,
        'due_at' => '2026-10-09T17:00:00.000Z',
        'due_has_time' => true,
        'estimate_minutes' => 90,
        'priority' => 'high',
    ], $overrides);
}

describe('editing a task', function () {
    test('every detail can be changed', function () {
        $user = User::factory()->create();
        $responsibility = $user->responsibilities()->create(['name' => 'Capstone']);
        $task = $user->tasks()->create(['title' => 'Old', 'priority' => 'normal']);

        $this->actingAs($user)->patch("/tasks/{$task->id}", taskPayload(['responsibility_id' => $responsibility->id]))
            ->assertSessionHasNoErrors();

        $task->refresh();

        expect($task->title)->toBe('Write report')
            ->and($task->notes)->toBe('Use the 2026 template.')
            ->and($task->priority)->toBe('high')
            ->and($task->estimate_minutes)->toBe(90)
            ->and($task->due_has_time)->toBeTrue()
            ->and($task->due_at->utc()->toIso8601String())->toBe('2026-10-09T17:00:00+00:00')
            ->and($task->responsibility_id)->toBe($responsibility->id);
    });

    test('a deadline, duration, notes and responsibility can be removed', function () {
        $user = User::factory()->create();
        $responsibility = $user->responsibilities()->create(['name' => 'Capstone']);
        $task = $user->tasks()->create(['title' => 'Old', 'priority' => 'high', 'notes' => 'x', 'estimate_minutes' => 30, 'due_at' => '2026-10-09 17:00:00']);
        $task->responsibility_id = $responsibility->id;
        $task->save();

        $this->actingAs($user)->patch("/tasks/{$task->id}", [
            'title' => 'Old', 'notes' => null, 'responsibility_id' => null, 'due_at' => null, 'due_has_time' => true, 'estimate_minutes' => null, 'priority' => 'normal',
        ])->assertSessionHasNoErrors();

        $task->refresh();

        expect($task->due_at)->toBeNull()
            ->and($task->notes)->toBeNull()
            ->and($task->estimate_minutes)->toBeNull()
            ->and($task->responsibility_id)->toBeNull()
            ->and($task->priority)->toBe('normal');
    });

    test('a date-only deadline is saved without a time', function () {
        $user = User::factory()->create();
        $task = $user->tasks()->create(['title' => 'Report', 'priority' => 'normal']);

        $this->actingAs($user)->patch("/tasks/{$task->id}", taskPayload(['due_at' => '2026-10-09T12:00:00Z', 'due_has_time' => false]))->assertSessionHasNoErrors();

        expect($task->fresh()->due_has_time)->toBeFalse()->and($task->fresh()->due_at->utc()->toDateString())->toBe('2026-10-09');
    });

    test('whether it is done is not changed by editing it', function () {
        $user = User::factory()->create();
        $task = $user->tasks()->create(['title' => 'Report', 'priority' => 'normal']);
        $task->completed_at = now();
        $task->save();

        $this->actingAs($user)->patch("/tasks/{$task->id}", taskPayload())->assertSessionHasNoErrors();

        expect($task->fresh()->completed_at)->not->toBeNull();
    });

    test('the edit is validated', function (array $overrides, string $field) {
        $user = User::factory()->create();
        $task = $user->tasks()->create(['title' => 'Report', 'priority' => 'normal']);

        $this->actingAs($user)->patch("/tasks/{$task->id}", taskPayload($overrides))->assertSessionHasErrors($field);
    })->with([
        'no title' => [['title' => ''], 'title'],
        'a bad priority' => [['priority' => 'urgent'], 'priority'],
        'a duration of zero' => [['estimate_minutes' => 0], 'estimate_minutes'],
        'a deadline without an offset' => [['due_at' => '2026-10-09T17:00:00'], 'due_at'],
        'notes that are too long' => [['notes' => str_repeat('a', 5001)], 'notes'],
    ]);

    test('a responsibility must be the users own and active', function () {
        $user = User::factory()->create();
        $task = $user->tasks()->create(['title' => 'Report', 'priority' => 'normal']);
        $other = User::factory()->create()->responsibilities()->create(['name' => 'Private']);

        $this->actingAs($user)->patch("/tasks/{$task->id}", taskPayload(['responsibility_id' => $other->id]))->assertSessionHasErrors('responsibility_id');
    });

    test('another users task cannot be edited, and guests are sent to log in', function () {
        $task = User::factory()->create()->tasks()->create(['title' => 'Private', 'priority' => 'normal']);

        $this->actingAs(User::factory()->create())->patch("/tasks/{$task->id}", taskPayload())->assertNotFound();
        expect($task->fresh()->title)->toBe('Private');

        auth()->logout();
        $this->patch("/tasks/{$task->id}", taskPayload())->assertRedirect('/login');
    });

    test('the dashboard sends each task its notes', function () {
        $user = User::factory()->create();
        $user->tasks()->create(['title' => 'Report', 'priority' => 'normal', 'notes' => 'Use the template.']);

        $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->where('tasks.0.notes', 'Use the template.'));
    });
});
