<?php

use App\Models\AssistantMessage;
use App\Models\CalendarSession;
use App\Models\Routine;
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
    expect(collect(ollamaRequests()[0]['tools'])->pluck('function.name')->all())->toBe(['list_tasks', 'get_schedule', 'list_coursework', 'list_routines', 'list_my_changes', 'create_task', 'plan_session', 'complete_task', 'update_task', 'delete_task', 'create_routine', 'update_routine', 'delete_routine']);
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

    expect(collect(ollamaRequests()[1]['messages'])->where('role', 'tool')->pluck('content')->implode(' '))->toContain('could not find that task');
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

function proposeAndGetId(string $tool, array $arguments): int
{
    fakeOllama([['calls' => [[$tool, $arguments]]], 'Suggested.']);

    return askAssistant('Do it')->json('messages.1.id');
}

function lastProposal(): array
{
    return test()->getJson('/assistant')->json('messages.1.proposals.0');
}

it('lists the user\'s routines, and only theirs', function () {
    weeklyRoutine($this->user, ['title' => 'Gym', 'days' => [1, 3, 5], 'start_time' => '18:00:00', 'duration_minutes' => 60]);
    weeklyRoutine(User::factory()->create(), ['title' => 'Not mine']);
    fakeOllama([['calls' => [['list_routines', []]]], 'Ok.']);

    askAssistant('What are my routines?');

    $tool = collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'];
    expect($tool)->toContain('Gym')->toContain('Mon')->toContain('Fri')->toContain('6:00 PM')->not->toContain('Not mine');
});

it('proposes a new routine, and makes it only once approved', function () {
    $id = proposeAndGetId('create_routine', ['title' => 'Gym', 'days' => ['monday', 'Wednesday', 'fri'], 'start_time' => '18:00', 'minutes' => 60]);

    expect(lastProposal())->toMatchArray(['summary' => 'Add routine “Gym” every Mon, Wed, Fri at 6:00 PM for 60 minutes', 'status' => 'pending', 'destructive' => false]);
    expect(Routine::count())->toBe(0);

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    $routine = Routine::firstOrFail();
    expect($routine->user_id)->toBe($this->user->id)->and($routine->days)->toBe([1, 3, 5])->and($routine->start_time)->toBe('18:00:00')->and($routine->duration_minutes)->toBe(60)
        ->and($routine->timezone)->toBe('America/New_York')->and($routine->starts_on->toDateString())->toBe('2026-10-07')->and($routine->ends_on)->toBeNull();
});

it('understands weekdays, weekends and daily for a routine', function (array $days, array $expected, string $summary) {
    $id = proposeAndGetId('create_routine', ['title' => 'Read', 'days' => $days, 'start_time' => '07:30', 'minutes' => 20]);

    expect(lastProposal()['summary'])->toContain("every {$summary} at 7:30 AM");

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();
    expect(Routine::firstOrFail()->days)->toBe($expected);
})->with([
    'weekdays' => [['weekdays'], [1, 2, 3, 4, 5], 'weekday'],
    'weekends' => [['weekends'], [6, 7], 'weekend day'],
    'daily' => [['daily'], [1, 2, 3, 4, 5, 6, 7], 'day'],
    'numbers' => [['2', '4'], [2, 4], 'Tue, Thu'],
]);

it('can end a new routine on a date, and start it on a chosen day', function () {
    $id = proposeAndGetId('create_routine', ['title' => 'Course', 'days' => ['tuesday'], 'start_time' => '09:00', 'minutes' => 90, 'start_date' => 'next monday', 'end_date' => '2026-12-18']);
    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    $routine = Routine::firstOrFail();
    expect($routine->starts_on->toDateString())->toBe('2026-10-12')->and($routine->ends_on->toDateString())->toBe('2026-12-18');
});

it('rejects a routine that makes no sense, and tells the model why', function (array $arguments) {
    fakeOllama([['calls' => [['create_routine', ['title' => 'X', 'days' => ['monday'], 'start_time' => '08:00', 'minutes' => 30, ...$arguments]]]], 'Sorry.']);

    askAssistant('Add it')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('error');
})->with([
    'no title' => [['title' => ' ']],
    'not a weekday' => [['days' => ['funday']]],
    'no days' => [['days' => []]],
    'bad time' => [['start_time' => '8am']],
    'too short' => [['minutes' => 2]],
    'too long' => [['minutes' => 2000]],
    'ends before it starts' => [['start_date' => '2026-10-20', 'end_date' => '2026-10-10']],
]);

it('changes a routine once approved, putting moved days back when the schedule changes', function () {
    $routine = weeklyRoutine($this->user, ['title' => 'Gym', 'days' => [1, 3], 'start_time' => '18:00:00', 'duration_minutes' => 60]);
    $routine->occurrences()->forceCreate(['occurs_on' => '2026-10-07', 'starts_at' => '2026-10-07 22:00:00', 'ends_at' => '2026-10-07 23:00:00', 'skipped' => false, 'completed_at' => null]);
    $id = proposeAndGetId('update_routine', ['routine_id' => $routine->id, 'days' => ['tuesday', 'thursday'], 'start_time' => '07:00']);

    expect(lastProposal()['summary'])->toBe('Change routine “Gym”: days to Tue, Thu, start 7:00 AM');
    expect($routine->fresh()->days)->toBe([1, 3]);

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    $routine->refresh();
    expect($routine->days)->toBe([2, 4])->and($routine->start_time)->toBe('07:00:00')->and($routine->title)->toBe('Gym')->and($routine->duration_minutes)->toBe(60)
        ->and($routine->occurrences()->first()->starts_at)->toBeNull();
});

it('keeps moved days when only the name changes', function () {
    $routine = weeklyRoutine($this->user, ['title' => 'Gym']);
    $routine->occurrences()->forceCreate(['occurs_on' => '2026-10-07', 'starts_at' => '2026-10-07 22:00:00', 'ends_at' => '2026-10-07 23:00:00', 'skipped' => false, 'completed_at' => null]);
    $id = proposeAndGetId('update_routine', ['routine_id' => $routine->id, 'title' => 'Lift']);

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    expect($routine->fresh()->title)->toBe('Lift')->and($routine->occurrences()->first()->starts_at)->not->toBeNull();
});

it('can give a routine no end date', function () {
    $routine = weeklyRoutine($this->user, ['ends_on' => '2026-12-01']);
    $id = proposeAndGetId('update_routine', ['routine_id' => $routine->id, 'end_date' => 'none']);

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    expect($routine->fresh()->ends_on)->toBeNull();
});

it('deletes a routine once approved, and warns that it is a deletion', function () {
    $routine = weeklyRoutine($this->user, ['title' => 'Gym']);
    $id = proposeAndGetId('delete_routine', ['routine_id' => $routine->id]);

    expect(lastProposal())->toMatchArray(['summary' => 'Delete routine “Gym” (Tue, Thu)', 'destructive' => true]);
    expect(Routine::count())->toBe(1);

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    expect(Routine::count())->toBe(0);
});

it('does not touch another user\'s routine, and says there is none', function (string $tool, array $extra) {
    $theirs = weeklyRoutine(User::factory()->create(), ['title' => 'Theirs']);
    fakeOllama([['calls' => [[$tool, ['routine_id' => $theirs->id, ...$extra]]]], 'Could not.']);

    askAssistant('Change it')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('could not find that routine');
    expect($theirs->fresh()->title)->toBe('Theirs');
})->with([['update_routine', ['title' => 'Mine now']], ['delete_routine', []]]);

it('fails an approved routine change if the routine is gone by then', function () {
    $routine = weeklyRoutine($this->user);
    $id = proposeAndGetId('delete_routine', ['routine_id' => $routine->id]);
    $routine->delete();

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertUnprocessable()->assertJsonPath('message', 'That routine no longer exists.');
});

it('asks for a change when updating a routine or task with nothing to change', function (string $tool, string $key) {
    $record = $key === 'routine_id' ? weeklyRoutine($this->user) : $this->user->tasks()->create(['title' => 'X', 'priority' => 'normal']);
    fakeOllama([['calls' => [[$tool, [$key => $record->id]]]], 'Sorry.']);

    askAssistant('Change it')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('Nothing to change');
})->with([['update_routine', 'routine_id'], ['update_task', 'task_id']]);

it('changes a task once approved, and only what was asked', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal', 'notes' => 'Keep these', 'estimate_minutes' => 90]);
    $id = proposeAndGetId('update_task', ['task_id' => $task->id, 'title' => 'Essay draft', 'due_date' => 'friday', 'due_time' => '17:00', 'priority' => 'high']);

    expect(lastProposal()['summary'])->toBe('Change “Essay”: title to “Essay draft”, deadline Fri Oct 9, 5:00 PM, priority high');
    expect($task->fresh()->title)->toBe('Essay');

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    $task->refresh();
    expect($task->title)->toBe('Essay draft')->and($task->priority)->toBe('high')->and($task->due_has_time)->toBeTrue()->and($task->due_at->toIso8601String())->toBe('2026-10-09T21:00:00+00:00')
        ->and($task->notes)->toBe('Keep these')->and($task->estimate_minutes)->toBe(90);
});

it('gives a task a date-only deadline, or removes it', function () {
    $task = $this->user->tasks()->create(['title' => 'Read', 'priority' => 'normal', 'due_at' => '2026-10-20 12:00:00', 'due_has_time' => false]);
    fakeOllama([
        ['calls' => [['update_task', ['task_id' => $task->id, 'due_date' => 'tomorrow']]]], 'Suggested.',
        ['calls' => [['update_task', ['task_id' => $task->id, 'due_date' => 'none']]]], 'Suggested.',
    ]);
    $first = askAssistant('Move it to tomorrow')->json('messages.1.id');
    $second = askAssistant('Remove the deadline')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$first}/proposals/0/approve")->assertOk();
    expect($task->fresh()->due_has_time)->toBeFalse()->and($task->fresh()->due_at->toIso8601String())->toBe('2026-10-08T12:00:00+00:00');

    $this->postJson("/assistant/messages/{$second}/proposals/0/approve")->assertOk();
    expect($task->fresh()->due_at)->toBeNull();
});

it('edits the notes of a task, and clears them', function () {
    $task = $this->user->tasks()->create(['title' => 'Read', 'priority' => 'normal']);
    fakeOllama([
        ['calls' => [['update_task', ['task_id' => $task->id, 'notes' => 'Chapter 3']]]], 'Suggested.',
        ['calls' => [['update_task', ['task_id' => $task->id, 'notes' => '']]]], 'Suggested.',
    ]);
    $first = askAssistant('Note chapter 3')->json('messages.1.id');
    $second = askAssistant('Clear the notes')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$first}/proposals/0/approve")->assertOk();
    expect($task->fresh()->notes)->toBe('Chapter 3');

    $this->postJson("/assistant/messages/{$second}/proposals/0/approve")->assertOk();
    expect($task->fresh()->notes)->toBeNull();
});

it('can edit a task that is already done', function () {
    $task = $this->user->tasks()->create(['title' => 'Old', 'priority' => 'normal']);
    $task->forceFill(['completed_at' => now()])->save();
    $id = proposeAndGetId('update_task', ['task_id' => $task->id, 'title' => 'Old, renamed']);

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    expect($task->fresh()->title)->toBe('Old, renamed');
});

it('rejects a bad task change and tells the model why', function (array $arguments) {
    $task = $this->user->tasks()->create(['title' => 'X', 'priority' => 'normal']);
    fakeOllama([['calls' => [['update_task', ['task_id' => $task->id, ...$arguments]]]], 'Sorry.']);

    askAssistant('Change it')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('error');
})->with([
    'bad priority' => [['priority' => 'urgent']],
    'time without a date' => [['due_time' => '10:00']],
    'bad date' => [['due_date' => 'someday']],
    'title too long' => [['title' => str_repeat('a', 256)]],
]);

it('deletes a task and its sessions once approved, and says so up front', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);
    plannedSession($this->user, $task, '2026-10-08 19:00:00');
    plannedSession($this->user, $task, '2026-10-09 19:00:00');
    $id = proposeAndGetId('delete_task', ['task_id' => $task->id]);

    expect(lastProposal())->toMatchArray(['summary' => 'Delete task “Essay” and its 2 planned sessions', 'destructive' => true]);
    expect(Task::count())->toBe(1);

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk()->assertJsonPath('message.proposals.0.result', 'Deleted the task “Essay”.');

    expect(Task::count())->toBe(0)->and(CalendarSession::count())->toBe(0);
});

it('does not touch another user\'s task when asked to change or delete it', function (string $tool, array $extra) {
    $theirs = User::factory()->create()->tasks()->create(['title' => 'Theirs', 'priority' => 'normal']);
    fakeOllama([['calls' => [[$tool, ['task_id' => $theirs->id, ...$extra]]]], 'Could not.']);

    askAssistant('Do it')->assertJsonPath('messages.1.proposals', []);

    expect($theirs->fresh()->title)->toBe('Theirs');
})->with([['update_task', ['title' => 'Mine now']], ['delete_task', []]]);

it('fails an approved task change if the task is gone by then', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);
    $id = proposeAndGetId('update_task', ['task_id' => $task->id, 'title' => 'Renamed']);
    $task->delete();

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertUnprocessable()->assertJsonPath('message', 'That task no longer exists.');
});

it('can suggest several changes in one reply, each approved on its own', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);
    fakeOllama([['calls' => [['update_task', ['task_id' => $task->id, 'priority' => 'high']], ['create_routine', ['title' => 'Write', 'days' => ['daily'], 'start_time' => '09:00', 'minutes' => 30]]]], 'Two suggestions.']);
    $id = askAssistant('Prioritise the essay and set a daily writing slot')->assertJsonCount(2, 'messages.1.proposals')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$id}/proposals/1/approve")->assertOk();

    expect(Routine::count())->toBe(1)->and($task->fresh()->priority)->toBe('normal');
    $this->getJson('/assistant')->assertJsonPath('messages.1.proposals.0.status', 'pending')->assertJsonPath('messages.1.proposals.1.status', 'approved');
});

it('asks a reasoning model to think briefly, and leaves the setting out for other models', function () {
    fakeOllama(['Ok.', 'Ok.']);

    config(['services.ollama.model' => 'gpt-oss:latest']);
    askAssistant('Hi');

    config(['services.ollama.model' => 'qwen2.5:7b']);
    askAssistant('Hi again');

    expect(ollamaRequests()[0])->toMatchArray(['model' => 'gpt-oss:latest', 'think' => 'low', 'keep_alive' => '10m'])->and(ollamaRequests()[1])->not->toHaveKey('think')->toMatchArray(['model' => 'qwen2.5:7b']);
});

it('finds a task or routine by its title, so the model need not know ids', function (string $tool, array $arguments, string $summary) {
    $this->user->tasks()->create(['title' => 'Scope and Time Management [One Submission Per Team]', 'priority' => 'normal']);
    $this->user->tasks()->create(['title' => 'Buy a charger', 'priority' => 'normal']);
    weeklyRoutine($this->user, ['title' => 'Prepare breakfast for students']);
    fakeOllama([['calls' => [[$tool, $arguments]]], 'Suggested.']);

    askAssistant('Do it')->assertJsonPath('messages.1.proposals.0.summary', $summary);
})->with([
    'a task by its exact title' => ['delete_task', ['task' => 'Buy a charger'], 'Delete task “Buy a charger”'],
    'a task by part of its title' => ['delete_task', ['task' => 'charger'], 'Delete task “Buy a charger”'],
    'in any case' => ['delete_task', ['task' => 'BUY A CHARGER'], 'Delete task “Buy a charger”'],
    'by the words in it, whatever the punctuation' => ['update_task', ['task' => 'Scope and Time Management (team)', 'priority' => 'high'], 'Change “Scope and Time Management [One Submission Per Team]”: priority high'],
    'a task to complete' => ['complete_task', ['task' => 'charger'], 'Mark “Buy a charger” as done'],
    'a routine by part of its name' => ['delete_routine', ['routine' => 'breakfast'], 'Delete routine “Prepare breakfast for students” (Tue, Thu)'],
    'a routine to change' => ['update_routine', ['routine' => 'Prepare breakfast', 'minutes' => 30], 'Change routine “Prepare breakfast for students”: length 30 minutes'],
]);

it('plans a session for a task named by its title', function () {
    $task = $this->user->tasks()->create(['title' => 'Write the essay', 'priority' => 'normal']);
    fakeOllama([['calls' => [['plan_session', ['task' => 'essay', 'date' => 'tomorrow', 'start_time' => '15:00', 'minutes' => 60]]]], 'Suggested.']);
    $id = askAssistant('Plan the essay tomorrow at 3')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    expect(CalendarSession::firstOrFail()->task_id)->toBe($task->id);
});

it('trusts the name the user gave over an id the model may have guessed', function () {
    $this->user->tasks()->create(['title' => 'Buy a charger', 'priority' => 'normal']);
    $other = $this->user->tasks()->create(['title' => 'Call mom', 'priority' => 'normal']);
    fakeOllama([['calls' => [['delete_task', ['task_id' => $other->id, 'task' => 'charger']]]], 'Suggested.']);

    askAssistant('Delete the charger')->assertJsonPath('messages.1.proposals.0.summary', 'Delete task “Buy a charger”');
});

it('uses the id when no name was given', function () {
    $this->user->tasks()->create(['title' => 'Buy a charger', 'priority' => 'normal']);
    $other = $this->user->tasks()->create(['title' => 'Call mom', 'priority' => 'normal']);
    fakeOllama([['calls' => [['delete_task', ['task_id' => $other->id]]]], 'Suggested.']);

    askAssistant('Delete it')->assertJsonPath('messages.1.proposals.0.summary', 'Delete task “Call mom”');
});

it('does not fall back to a guessed id when the name matches nothing', function () {
    $other = $this->user->tasks()->create(['title' => 'Call mom', 'priority' => 'normal']);
    fakeOllama([['calls' => [['delete_task', ['task_id' => $other->id, 'task' => 'unicorn']]]], 'Not found.']);

    askAssistant('Delete the unicorn')->assertJsonPath('messages.1.proposals', []);
});

it('tells apart routines whose names differ by a single letter', function () {
    weeklyRoutine($this->user, ['title' => 'Gym - Upper A']);
    $b = weeklyRoutine($this->user, ['title' => 'Gym - Upper B']);
    fakeOllama([
        ['calls' => [['delete_routine', ['routine' => 'gym upper b', 'routine_id' => $b->id + 99]]]], 'S.',
        ['calls' => [['delete_routine', ['routine' => 'Upper']]]], 'Which one?',
    ]);

    askAssistant('Delete Gym Upper B')->assertJsonPath('messages.1.proposals.0.summary', 'Delete routine “Gym - Upper B” (Tue, Thu)');
    askAssistant('Delete upper')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[3]['messages'])->where('role', 'tool')->last()['content'])->toContain('Several routines match')->toContain('Gym - Upper A')->toContain('Gym - Upper B');
});

it('ignores filler words around a name', function () {
    $this->user->tasks()->create(['title' => 'Buy a charger', 'priority' => 'normal']);
    fakeOllama([['calls' => [['delete_task', ['task' => 'my charger task']]]], 'Suggested.']);

    askAssistant('Delete my charger task')->assertJsonPath('messages.1.proposals.0.summary', 'Delete task “Buy a charger”');
});

it('asks the model to check with the user when several tasks match', function () {
    $this->user->tasks()->create(['title' => 'Homework 1', 'priority' => 'normal']);
    $this->user->tasks()->create(['title' => 'Homework 2', 'priority' => 'normal']);
    fakeOllama([['calls' => [['delete_task', ['task' => 'homework']]]], 'Which one?']);

    askAssistant('Delete my homework task')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('Several tasks match')->toContain('Homework 1')->toContain('Homework 2')->toContain('Ask the user which');
});

it('prefers the exact title when one task\'s title is part of another\'s', function () {
    $this->user->tasks()->create(['title' => 'Read', 'priority' => 'normal']);
    $this->user->tasks()->create(['title' => 'Read chapter 3', 'priority' => 'normal']);
    fakeOllama([['calls' => [['delete_task', ['task' => 'read']]]], 'Suggested.']);

    askAssistant('Delete read')->assertJsonPath('messages.1.proposals.0.summary', 'Delete task “Read”');
});

it('only looks at open tasks when completing or planning, and any task when editing', function () {
    $done = $this->user->tasks()->create(['title' => 'Old essay', 'priority' => 'normal']);
    $done->forceFill(['completed_at' => now()])->save();
    $this->user->tasks()->create(['title' => 'Old essay revision', 'priority' => 'normal']);
    fakeOllama([['calls' => [['complete_task', ['task' => 'old essay']]]], 'Suggested.', ['calls' => [['update_task', ['task' => 'old essay', 'priority' => 'low']]]], 'Suggested.']);

    askAssistant('Done with the old essay')->assertJsonPath('messages.1.proposals.0.summary', 'Mark “Old essay revision” as done');
    askAssistant('Make the old essay low priority')->assertJsonPath('messages.1.proposals.0.summary', 'Change “Old essay”: priority low');
});

it('does not match another user\'s task by title', function () {
    User::factory()->create()->tasks()->create(['title' => 'Secret plan', 'priority' => 'normal']);
    fakeOllama([['calls' => [['delete_task', ['task' => 'secret plan']]]], 'Not found.']);

    askAssistant('Delete the secret plan')->assertJsonPath('messages.1.proposals', []);
});

it('says it could not find a task or routine that does not exist, and how to look', function (string $tool, array $arguments, string $hint) {
    fakeOllama([['calls' => [[$tool, $arguments]]], 'Sorry.']);

    askAssistant('Do it')->assertJsonPath('messages.1.proposals', []);

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain($hint);
})->with([
    'no such task' => ['delete_task', ['task' => 'unicorn'], 'list_tasks'],
    'no task given' => ['delete_task', [], 'list_tasks'],
    'no such routine' => ['delete_routine', ['routine' => 'unicorn'], 'list_routines'],
    'no routine given' => ['update_routine', ['title' => 'X'], 'list_routines'],
]);
