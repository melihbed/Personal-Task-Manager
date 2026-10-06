<?php

use App\Models\User;

test('a task can be completed and reopened', function () {
    $user = User::factory()->create();
    $task = $user->tasks()->create(['title' => 'Call', 'priority' => 'normal']);

    $this->actingAs($user)->patch("/tasks/{$task->id}/completion", ['completed' => true])->assertSessionHasNoErrors();
    expect($task->fresh()->completed_at)->not->toBeNull();

    $this->actingAs($user)->patch("/tasks/{$task->id}/completion", ['completed' => false])->assertSessionHasNoErrors();
    expect($task->fresh()->completed_at)->toBeNull();
});

test('another users task cannot be completed', function () {
    $task = User::factory()->create()->tasks()->create(['title' => 'Private', 'priority' => 'normal']);

    $this->actingAs(User::factory()->create())
        ->patch("/tasks/{$task->id}/completion", ['completed' => true])
        ->assertNotFound();
});
