<?php

use App\Models\User;

test('guests cannot create tasks', function () {
    $this->post('/tasks', ['title' => 'Study'])->assertRedirect('/login');
});

test('a task without a responsibility goes to the inbox', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/')
        ->post('/tasks', ['title' => 'Study', 'responsibility_id' => null])
        ->assertRedirect('/');

    $this->assertDatabaseHas('tasks', [
        'user_id' => $user->id,
        'title' => 'Study',
        'responsibility_id' => null,
        'priority' => 'normal',
    ]);
});

test('a task can be assigned to one of the users responsibilities', function () {
    $user = User::factory()->create();
    $responsibility = $user->responsibilities()->create(['name' => 'Capstone']);

    $this->actingAs($user)
        ->post('/tasks', ['title' => 'Write intro', 'responsibility_id' => $responsibility->id])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('tasks', [
        'user_id' => $user->id,
        'title' => 'Write intro',
        'responsibility_id' => $responsibility->id,
    ]);
});

test('a task cannot be assigned to another users responsibility', function () {
    $user = User::factory()->create();
    $other = User::factory()->create()->responsibilities()->create(['name' => 'Private']);

    $this->actingAs($user)
        ->post('/tasks', ['title' => 'Sneaky', 'responsibility_id' => $other->id])
        ->assertSessionHasErrors('responsibility_id');

    $this->assertDatabaseMissing('tasks', ['title' => 'Sneaky']);
});

test('a task cannot be assigned to an archived responsibility', function () {
    $user = User::factory()->create();
    $archived = $user->responsibilities()->create(['name' => 'Old']);
    $archived->archived_at = now();
    $archived->save();

    $this->actingAs($user)
        ->post('/tasks', ['title' => 'Too late', 'responsibility_id' => $archived->id])
        ->assertSessionHasErrors('responsibility_id');

    $this->assertDatabaseMissing('tasks', ['title' => 'Too late']);
});

test('a title is required', function () {
    $this->actingAs(User::factory()->create())
        ->post('/tasks', ['title' => ''])
        ->assertSessionHasErrors('title');
});

test('a task can store a due time, duration and priority', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/tasks', [
            'title' => 'Take clothes out of washing machine',
            'due_at' => '2026-10-07T18:00:00.000Z',
            'estimate_minutes' => 5,
            'priority' => 'high',
        ])->assertSessionHasNoErrors();

    $task = $user->tasks()->firstOrFail();

    expect($task->estimate_minutes)->toBe(5)
        ->and($task->priority)->toBe('high')
        ->and($task->due_at->utc()->toIso8601String())->toBe('2026-10-07T18:00:00+00:00');
});

test('due time offsets are converted to UTC', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/tasks', ['title' => 'Call', 'due_at' => '2026-10-07T14:00:00-04:00'])
        ->assertSessionHasNoErrors();

    expect($user->tasks()->firstOrFail()->due_at->utc()->toIso8601String())->toBe('2026-10-07T18:00:00+00:00');
});

test('a due time without an offset is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post('/tasks', ['title' => 'Call', 'due_at' => '2026-10-07T14:00:00'])
        ->assertSessionHasErrors('due_at');
});

test('invalid duration and priority are rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post('/tasks', ['title' => 'Call', 'estimate_minutes' => 0, 'priority' => 'urgent'])
        ->assertSessionHasErrors(['estimate_minutes', 'priority']);
});

test('a deadline defaults to having a specific time', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/tasks', ['title' => 'Call', 'due_at' => '2026-10-07T18:00:00Z']);

    expect($user->tasks()->firstOrFail()->due_has_time)->toBeTrue();
});

test('a date-only deadline is stored without a time', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/tasks', ['title' => 'Submit report', 'due_at' => '2026-10-07T12:00:00Z', 'due_has_time' => false])
        ->assertSessionHasNoErrors();

    $task = $user->tasks()->firstOrFail();

    expect($task->due_has_time)->toBeFalse()
        ->and($task->due_at->utc()->toDateString())->toBe('2026-10-07');
});
