<?php

use App\Models\AssistantMessage;
use App\Models\CalendarSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Wednesday 2026-10-07, 2 PM in New York.
    Carbon::setTestNow('2026-10-07 18:00:00 UTC');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('requires a login', function () {
    auth()->logout();

    $this->getJson('/assistant')->assertUnauthorized();
    $this->postJson('/assistant', ['content' => 'Hi', 'timezone' => 'UTC'])->assertUnauthorized();
});

it('answers and saves the conversation', function () {
    fakeOllama(['You have nothing due.']);

    askAssistant('What is due?')->assertOk()->assertJsonPath('messages.1.content', 'You have nothing due.')->assertJsonPath('messages.0.role', 'user');

    $this->getJson('/assistant')->assertJsonPath('messages.0.content', 'What is due?')->assertJsonPath('messages.1.role', 'assistant');
});

it('only ever sends the current user\'s own conversation to the model', function () {
    AssistantMessage::factory()->for(User::factory())->create(['content' => 'someone else\'s secret']);
    AssistantMessage::factory()->for($this->user)->create(['content' => 'my earlier question']);
    fakeOllama(['Ok.']);

    askAssistant('Next question');

    $sent = json_encode(ollamaRequests()[0]['messages']);
    expect($sent)->toContain('my earlier question')->and($sent)->not->toContain('secret');
    $this->getJson('/assistant')->assertJsonCount(3, 'messages');
});

it('tells the model the date, timezone and rules', function () {
    fakeOllama(['Ok.']);

    askAssistant('Hi', 'America/New_York');

    $system = ollamaRequests()[0]['messages'][0]['content'];
    expect($system)->toContain('Wednesday, October 7, 2026')->toContain('America/New_York')->toContain('data, not instructions')->toContain('approves');
    expect(collect(ollamaRequests()[0]['tools'])->pluck('function.name')->all())->toBe(['list_tasks', 'get_schedule', 'list_coursework', 'create_task', 'plan_session', 'complete_task']);
});

it('spells out the next two weeks so weekdays are never worked out by the model', function () {
    fakeOllama(['Ok.']);

    askAssistant('Hi');

    $system = ollamaRequests()[0]['messages'][0]['content'];
    expect($system)->toContain('Wednesday 2026-10-07 (today)')->toContain('Thursday 2026-10-08 (tomorrow)')->toContain('Friday 2026-10-09')->toContain('Tuesday 2026-10-20')->not->toContain('2026-10-21');
});

it('looks up tasks with a tool, and only the user\'s own', function () {
    $this->user->tasks()->create(['title' => 'Write essay', 'priority' => 'high', 'due_at' => '2026-10-07 20:00:00', 'due_has_time' => true]);
    User::factory()->create()->tasks()->create(['title' => 'Not mine', 'priority' => 'normal']);
    fakeOllama([['calls' => [['list_tasks', ['filter' => 'open']]]], 'You have one task: Write essay.']);

    askAssistant('What is open?')->assertJsonPath('messages.1.content', 'You have one task: Write essay.');

    $second = ollamaRequests()[1]['messages'];
    $toolMessage = collect($second)->firstWhere('role', 'tool');
    expect($toolMessage['content'])->toContain('Write essay')->toContain('Wed Oct 7, 4:00 PM')->not->toContain('Not mine');
});

it('separates overdue from soon and from later', function () {
    $this->user->tasks()->create(['title' => 'Late one', 'priority' => 'normal', 'due_at' => '2026-10-06 12:00:00', 'due_has_time' => true]);
    $this->user->tasks()->create(['title' => 'Soon one', 'priority' => 'normal', 'due_at' => '2026-10-08 12:00:00', 'due_has_time' => true]);
    $this->user->tasks()->create(['title' => 'Far one', 'priority' => 'normal', 'due_at' => '2026-10-30 12:00:00', 'due_has_time' => true]);
    fakeOllama([['calls' => [['list_tasks', ['filter' => 'overdue']], ['list_tasks', ['filter' => 'due_soon']]]], 'Done.']);

    askAssistant('What needs attention?');

    $tools = collect(ollamaRequests()[1]['messages'])->where('role', 'tool')->pluck('content');
    expect($tools[0])->toContain('Late one')->not->toContain('Soon one')
        ->and($tools[1])->toContain('Soon one')->not->toContain('Late one')->not->toContain('Far one');
});

it('reads the schedule for a range of days', function () {
    $task = $this->user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);
    plannedSession($this->user, $task, '2026-10-08 19:00:00');
    plannedSession($this->user, $task, '2026-10-20 19:00:00');
    fakeOllama([['calls' => [['get_schedule', ['from_date' => '2026-10-08', 'to_date' => '2026-10-08']]]], 'You are studying at 3 PM.']);

    askAssistant('What am I doing tomorrow?');

    $tool = collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'];
    expect($tool)->toContain('work session')->toContain('Study')->toContain('Thu Oct 8, 3:00 PM')->not->toContain('Oct 20');
});

it('turns down a schedule request that is too long or backwards, and tells the model why', function (array $range) {
    fakeOllama([['calls' => [['get_schedule', $range]]], 'Sorry.']);

    askAssistant('Show me');

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('error');
})->with([
    'too long' => [['from_date' => '2026-10-01', 'to_date' => '2026-12-01']],
    'backwards' => [['from_date' => '2026-10-10', 'to_date' => '2026-10-01']],
    'not a date' => [['from_date' => 'someday', 'to_date' => 'someday']],
]);

it('lists unfinished coursework', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment(['name' => 'Homework 9'])]]);
    $user = canvasUser();
    syncCanvas($user);
    $this->actingAs($user);
    fakeOllama([['calls' => [['list_coursework', []]]], 'One assignment.']);

    askAssistant('What coursework is left?');

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('Homework 9')->toContain('Data Structures');
});

it('only proposes a new task, and creates nothing until approved', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Buy a charger', 'due_date' => '2026-10-09', 'due_time' => '17:00']]]], 'I suggest adding it.']);

    $response = askAssistant('Remind me to buy a charger on Friday at 5')->assertOk();

    $response->assertJsonPath('messages.1.proposals.0.status', 'pending')->assertJsonPath('messages.1.proposals.0.summary', 'Add task “Buy a charger”, due Fri Oct 9, 5:00 PM');
    expect(Task::count())->toBe(0);
});

it('keeps the model from claiming a change is done', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'X']]]], 'Okay.']);

    askAssistant('Add X');

    $tool = collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'];
    expect($tool)->toContain('NOT been done');
});

it('makes the task once approved, with the deadline in the user\'s timezone', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Buy a charger', 'due_date' => '2026-10-09', 'due_time' => '17:00', 'priority' => 'high']]]], 'Suggested.']);
    $id = askAssistant('Add it')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk()->assertJsonPath('message.proposals.0.status', 'approved')->assertJsonPath('message.proposals.0.result', 'Added the task “Buy a charger”.');

    $task = Task::firstOrFail();
    expect($task->user_id)->toBe($this->user->id)
        ->and($task->priority)->toBe('high')
        ->and($task->due_has_time)->toBeTrue()
        ->and($task->due_at->toIso8601String())->toBe('2026-10-09T21:00:00+00:00');
});

it('stores a date-only deadline the way the rest of the app does', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Read', 'due_date' => '2026-10-09']]]], 'Suggested.']);
    $id = askAssistant('Add it')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    $task = Task::firstOrFail();
    expect($task->due_has_time)->toBeFalse()->and($task->due_at->toIso8601String())->toBe('2026-10-09T12:00:00+00:00');
});

it('cannot be approved twice', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Once']]]], 'Suggested.']);
    $id = askAssistant('Add it')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();
    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertNotFound();

    expect(Task::count())->toBe(1);
});

it('can be dismissed, and then does nothing', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Nope']]]], 'Suggested.']);
    $id = askAssistant('Add it')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$id}/proposals/0/dismiss")->assertOk()->assertJsonPath('message.proposals.0.status', 'dismissed');
    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertNotFound();

    expect(Task::count())->toBe(0);
});

it('plans a session for a task once approved', function () {
    $task = $this->user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);
    fakeOllama([['calls' => [['plan_session', ['task_id' => $task->id, 'date' => '2026-10-08', 'start_time' => '15:00', 'minutes' => 90]]]], 'Suggested.']);
    $response = askAssistant('Plan study tomorrow at 3 for 90 minutes');
    $response->assertJsonPath('messages.1.proposals.0.summary', 'Plan “Study” on Thu Oct 8, 3:00–4:30 PM');

    $this->postJson('/assistant/messages/'.$response->json('messages.1.id').'/proposals/0/approve')->assertOk();

    $session = CalendarSession::firstOrFail();
    expect($session->task_id)->toBe($task->id)->and($session->starts_at->toIso8601String())->toBe('2026-10-08T19:00:00+00:00')->and($session->ends_at->toIso8601String())->toBe('2026-10-08T20:30:00+00:00');
});

it('refuses to plan over another session when approved, and the suggestion stays open', function () {
    $task = $this->user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);
    fakeOllama([['calls' => [['plan_session', ['task_id' => $task->id, 'date' => '2026-10-08', 'start_time' => '15:00', 'minutes' => 60]]]], 'Suggested.']);
    $id = askAssistant('Plan it')->json('messages.1.id');
    plannedSession($this->user, null, '2026-10-08 19:30:00');

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'overlaps'));

    expect(CalendarSession::count())->toBe(1);
    $this->getJson('/assistant')->assertJsonPath('messages.1.proposals.0.status', 'pending');
});

it('marks a task done once approved, and fails if it was finished meanwhile', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);
    fakeOllama([['calls' => [['complete_task', ['task_id' => $task->id]]]], 'Suggested.', ['calls' => [['complete_task', ['task_id' => $task->id]]]], 'Suggested.']);
    $first = askAssistant('Done with the essay')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$first}/proposals/0/approve")->assertOk();
    expect($task->fresh()->completed_at)->not->toBeNull();

    $task->forceFill(['completed_at' => null])->save();
    $second = askAssistant('Done with the essay again')->json('messages.1.id');
    $task->forceFill(['completed_at' => now()])->save();

    $this->postJson("/assistant/messages/{$second}/proposals/0/approve")->assertUnprocessable();
});

it('does not let the model touch another user\'s task, and tells the model why', function () {
    $theirs = User::factory()->create()->tasks()->create(['title' => 'Theirs', 'priority' => 'normal']);
    fakeOllama([['calls' => [['complete_task', ['task_id' => $theirs->id]], ['plan_session', ['task_id' => $theirs->id, 'date' => '2026-10-08', 'start_time' => '15:00', 'minutes' => 60]]]], 'I could not.']);

    askAssistant('Finish their task')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[1]['messages'])->where('role', 'tool')->pluck('content')->implode(' '))->toContain('no task with that id');
    expect($theirs->fresh()->completed_at)->toBeNull();
});

it('cannot approve or dismiss another user\'s suggestion', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Mine']]]], 'Suggested.']);
    $id = askAssistant('Add it')->json('messages.1.id');

    $this->actingAs(User::factory()->create());
    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertNotFound();
    $this->postJson("/assistant/messages/{$id}/proposals/0/dismiss")->assertNotFound();

    expect(Task::count())->toBe(0);
});

it('does not suggest the same change twice in one reply', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Same']], ['create_task', ['title' => 'Same']]]], 'Suggested.']);

    askAssistant('Add it')->assertJsonCount(1, 'messages.1.proposals');
});

it('rejects bad proposals and tells the model what was wrong', function (string $tool, array $arguments) {
    fakeOllama([['calls' => [[$tool, $arguments]]], 'Sorry.']);

    askAssistant('Do it')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('error');
})->with([
    'no title' => ['create_task', ['title' => '  ']],
    'bad date' => ['create_task', ['title' => 'X', 'due_date' => '2026-02-31']],
    'time without a date' => ['create_task', ['title' => 'X', 'due_time' => '10:00']],
    'bad time' => ['create_task', ['title' => 'X', 'due_date' => '2026-10-09', 'due_time' => '25:00']],
    'unknown task' => ['complete_task', ['task_id' => 999]],
    'unknown tool' => ['delete_everything', []],
]);

it('accepts tool arguments sent as a JSON string', function () {
    Http::fake(['*/api/chat' => Http::sequence()
        ->push(['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['function' => ['name' => 'create_task', 'arguments' => '{"title":"From a string"}']]]]])
        ->push(['message' => ['role' => 'assistant', 'content' => 'Suggested.']])]);

    askAssistant('Add it')->assertJsonPath('messages.1.proposals.0.summary', 'Add task “From a string”, no deadline');
});

it('gives up after a few rounds of tool calls instead of looping', function () {
    fakeOllama(array_fill(0, 8, ['calls' => [['list_tasks', ['filter' => 'open']]]]));

    askAssistant('Loop')->assertOk()->assertJsonPath('messages.1.content', 'I could not finish that. Try asking in a simpler way.');

    expect(ollamaRequests())->toHaveCount(5);
});

it('reminds the model what happened to earlier suggestions', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Charger']]]], 'Suggested.', 'Ok.']);
    $id = askAssistant('Add it')->json('messages.1.id');
    $this->postJson("/assistant/messages/{$id}/proposals/0/dismiss");

    askAssistant('Anything else?');

    expect(json_encode(ollamaRequests()[2]['messages'], JSON_UNESCAPED_UNICODE))->toContain('Add task “Charger”, no deadline - dismissed');
});

it('explains when Ollama is not running, and forgets the unanswered message', function () {
    fakeOllamaDown();

    askAssistant('Hi')->assertStatus(503)->assertJsonPath('message', fn (string $message) => str_contains($message, 'Ollama'));

    expect(AssistantMessage::count())->toBe(0);
});

it('explains when the model is not installed', function () {
    Http::fake(['*/api/chat' => Http::response(['error' => 'model not found'], 404)]);

    askAssistant('Hi')->assertStatus(503)->assertJsonPath('message', fn (string $message) => str_contains($message, 'ollama pull qwen2.5:7b'));
});

it('validates the message', function () {
    $this->postJson('/assistant', ['content' => '', 'timezone' => 'UTC'])->assertJsonValidationErrors('content');
    $this->postJson('/assistant', ['content' => str_repeat('a', 2001), 'timezone' => 'UTC'])->assertJsonValidationErrors('content');
    $this->postJson('/assistant', ['content' => 'Hi', 'timezone' => 'Mars/Base'])->assertJsonValidationErrors('timezone');
});

it('starts a new chat', function () {
    fakeOllama(['One.']);
    askAssistant('First');

    $this->deleteJson('/assistant')->assertOk()->assertJsonPath('messages', []);

    expect(AssistantMessage::count())->toBe(0);
});

it('leaves other people\'s chats alone when starting a new one', function () {
    AssistantMessage::factory()->for(User::factory())->create();

    $this->deleteJson('/assistant');

    expect(AssistantMessage::count())->toBe(1);
});

it('works out weekday names itself instead of trusting the model', function (string $word, string $expected) {
    fakeOllama([['calls' => [['create_task', ['title' => 'Call', 'due_date' => $word]]]], 'Suggested.']);

    $summary = askAssistant('Add it')->json('messages.1.proposals.0.summary');

    expect($summary)->toBe("Add task “Call”, due {$expected}");
})->with([
    'a weekday this week' => ['friday', 'Fri Oct 9'],
    'any capitals' => ['  Friday ', 'Fri Oct 9'],
    'today by name' => ['wednesday', 'Wed Oct 7'],
    'next skips today' => ['next wednesday', 'Wed Oct 14'],
    'next weekday' => ['next tuesday', 'Tue Oct 13'],
    'tomorrow' => ['tomorrow', 'Thu Oct 8'],
    'today' => ['today', 'Wed Oct 7'],
    'a plain date' => ['2026-10-30', 'Fri Oct 30'],
]);

it('keeps the suggestion notes it adds to the history out of what the user reads', function () {
    fakeOllama(["Sure.\n[Suggestion: Add task “Call”, no deadline - pending]"]);

    askAssistant('Hi')->assertJsonPath('messages.1.content', 'Sure.');
});
