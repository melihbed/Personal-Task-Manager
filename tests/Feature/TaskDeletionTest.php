<?php

use App\Models\CalendarSession;
use App\Models\Task;
use App\Models\User;

function sessionFor(User $user, Task $task, string $start = '2026-10-07 14:00:00'): CalendarSession
{
    $session = new CalendarSession(['starts_at' => $start, 'ends_at' => date('Y-m-d H:i:s', strtotime($start) + 3600)]);
    $session->user()->associate($user);
    $session->task()->associate($task);
    $session->save();

    return $session;
}

test('a task can be deleted', function () {
    $user = User::factory()->create();
    $task = $user->tasks()->create(['title' => 'Call', 'priority' => 'normal']);

    $this->actingAs($user)->delete("/tasks/{$task->id}")->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
});

test('deleting a task also removes its calendar sessions', function () {
    $user = User::factory()->create();
    $task = $user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);
    $other = $user->tasks()->create(['title' => 'Read', 'priority' => 'normal']);
    $removed = sessionFor($user, $task);
    sessionFor($user, $task, '2026-10-08 14:00:00');
    $kept = sessionFor($user, $other, '2026-10-09 14:00:00');

    $this->actingAs($user)->delete("/tasks/{$task->id}")->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('calendar_sessions', ['id' => $removed->id]);
    expect(CalendarSession::where('task_id', $task->id)->count())->toBe(0)
        ->and(CalendarSession::find($kept->id))->not->toBeNull();
});

test('another users task cannot be deleted', function () {
    $task = User::factory()->create()->tasks()->create(['title' => 'Private', 'priority' => 'normal']);

    $this->actingAs(User::factory()->create())->delete("/tasks/{$task->id}")->assertNotFound();

    $this->assertDatabaseHas('tasks', ['id' => $task->id]);
});

test('guests cannot delete tasks', function () {
    $task = User::factory()->create()->tasks()->create(['title' => 'Private', 'priority' => 'normal']);

    $this->delete("/tasks/{$task->id}")->assertRedirect('/login');
    $this->assertDatabaseHas('tasks', ['id' => $task->id]);
});

test('clearing completed tasks removes only the users visible completed tasks and their sessions', function () {
    $user = User::factory()->create();
    $active = $user->responsibilities()->create(['name' => 'Home']);
    $archived = $user->responsibilities()->create(['name' => 'Old']);
    $archived->archived_at = now();
    $archived->save();

    $make = function (string $title, ?int $responsibilityId, bool $done) use ($user) {
        $task = $user->tasks()->create(['title' => $title, 'priority' => 'normal']);
        $task->responsibility_id = $responsibilityId;
        $task->completed_at = $done ? now() : null;
        $task->save();

        return $task;
    };

    $doneInbox = $make('Done inbox', null, true);
    $doneActive = $make('Done active', $active->id, true);
    $openActive = $make('Open active', $active->id, false);
    $doneArchived = $make('Done archived', $archived->id, true);
    $session = sessionFor($user, $doneInbox);
    $stranger = User::factory()->create()->tasks()->create(['title' => 'Stranger done', 'priority' => 'normal']);
    $stranger->completed_at = now();
    $stranger->save();

    $this->actingAs($user)->delete('/completed-tasks')->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('tasks', ['id' => $doneInbox->id]);
    $this->assertDatabaseMissing('tasks', ['id' => $doneActive->id]);
    $this->assertDatabaseMissing('calendar_sessions', ['id' => $session->id]);
    $this->assertDatabaseHas('tasks', ['id' => $openActive->id]);
    $this->assertDatabaseHas('tasks', ['id' => $doneArchived->id]);
    $this->assertDatabaseHas('tasks', ['id' => $stranger->id]);
});

test('guests cannot clear completed tasks', function () {
    $this->delete('/completed-tasks')->assertRedirect('/login');
});
