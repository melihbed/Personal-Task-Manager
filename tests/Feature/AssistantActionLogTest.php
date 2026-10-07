<?php

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Wednesday 2026-10-07, 2 PM in New York.
    Carbon::setTestNow('2026-10-07 18:00:00 UTC');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function approve(string $tool, array $arguments): int
{
    fakeOllama([['calls' => [[$tool, $arguments]]], 'Suggested.']);
    $id = askAssistant('Do it')->json('messages.1.id');
    test()->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();

    return $id;
}

function lastAction(): AssistantAction
{
    return AssistantAction::latest('id')->firstOrFail();
}

it('records an approved change with what changed from and to', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal', 'due_at' => '2026-10-20 12:00:00', 'due_has_time' => false]);

    $id = approve('update_task', ['task' => 'essay', 'due_date' => 'friday', 'due_time' => '17:00', 'priority' => 'high']);

    $action = lastAction();
    expect($action)->toMatchArray(['user_id' => $this->user->id, 'assistant_message_id' => $id, 'type' => 'update_task', 'status' => 'applied', 'subject_type' => 'task', 'subject_id' => $task->id, 'subject_title' => 'Essay', 'result' => 'Updated “Essay”.'])
        ->and($action->summary)->toBe('Change “Essay”: deadline Fri Oct 9, 5:00 PM, priority high')
        ->and($action->changes)->toBe([
            ['label' => 'Deadline', 'from' => 'Tue Oct 20 (date only)', 'to' => 'Fri Oct 9, 5:00 PM'],
            ['label' => 'Priority', 'from' => 'normal', 'to' => 'high'],
        ]);
});

it('records a new task with its starting values', function () {
    approve('create_task', ['title' => 'Buy a charger', 'due_date' => '2026-10-09', 'due_time' => '17:00', 'priority' => 'high']);

    expect(lastAction()->changes)->toBe([
        ['label' => 'Title', 'from' => null, 'to' => 'Buy a charger'],
        ['label' => 'Deadline', 'from' => null, 'to' => 'Fri Oct 9, 5:00 PM'],
        ['label' => 'Priority', 'from' => null, 'to' => 'high'],
        ['label' => 'Status', 'from' => null, 'to' => 'Open'],
    ]);
});

it('records a deleted task with what it was, and keeps the record after it is gone', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'high', 'notes' => 'Chapter 3']);

    approve('delete_task', ['task' => 'Essay']);

    $action = lastAction();
    expect($action->status)->toBe('applied')->and($action->subject_id)->toBe($task->id)->and($action->subject_title)->toBe('Essay')
        ->and(collect($action->changes)->pluck('to')->filter()->all())->toBe([])
        ->and(collect($action->changes)->firstWhere('label', 'Notes'))->toBe(['label' => 'Notes', 'from' => 'Chapter 3', 'to' => null]);
    expect($this->user->tasks()->count())->toBe(0);
});

it('records completing a task as open to done', function () {
    approve('complete_task', ['task' => 'essay'] + ['task_id' => $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal'])->id]);

    expect(lastAction()->changes)->toBe([['label' => 'Status', 'from' => 'Open', 'to' => 'Done']]);
});

it('records a planned work session', function () {
    $task = $this->user->tasks()->create(['title' => 'Study', 'priority' => 'normal']);

    approve('plan_session', ['task' => 'study', 'date' => 'tomorrow', 'start_time' => '15:00', 'minutes' => 90]);

    $action = lastAction();
    expect($action)->toMatchArray(['subject_type' => 'session', 'subject_title' => 'Study'])->and($action->changes)->toBe([['label' => 'Work session', 'from' => null, 'to' => 'Thu Oct 8, 3:00–4:30 PM']]);
});

it('records a routine being created, changed and deleted', function () {
    fakeOllama([
        ['calls' => [['create_routine', ['title' => 'Gym', 'days' => ['monday', 'wednesday'], 'start_time' => '18:00', 'minutes' => 60]]]], 'S.',
        ['calls' => [['update_routine', ['routine' => 'gym', 'days' => ['tuesday'], 'start_time' => '07:00']]]], 'S.',
        ['calls' => [['delete_routine', ['routine' => 'gym']]]], 'S.',
    ]);

    foreach (['Add gym', 'Move gym', 'Delete gym'] as $text) {
        $id = askAssistant($text)->json('messages.1.id');
        $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();
    }

    [$created, $changed, $deleted] = AssistantAction::orderBy('id')->get()->all();
    expect($created->changes)->toContain(['label' => 'Days', 'from' => null, 'to' => 'Mon, Wed'])->toContain(['label' => 'Starts at', 'from' => null, 'to' => '6:00 PM'])
        ->and($changed->changes)->toBe([['label' => 'Days', 'from' => 'Mon, Wed', 'to' => 'Tue'], ['label' => 'Starts at', 'from' => '6:00 PM', 'to' => '7:00 AM']])
        ->and($deleted->changes)->toContain(['label' => 'Days', 'from' => 'Tue', 'to' => null])
        ->and([$created->subject_type, $changed->subject_type, $deleted->subject_type])->toBe(['routine', 'routine', 'routine'])
        ->and(Routine::count())->toBe(0);
});

it('records a change that could not be made, with why', function () {
    $task = $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);
    fakeOllama([['calls' => [['update_task', ['task' => 'essay', 'priority' => 'high']]]], 'Suggested.']);
    $id = askAssistant('Do it')->json('messages.1.id');
    $task->delete();

    $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertUnprocessable();

    $action = lastAction();
    expect($action)->toMatchArray(['status' => 'failed', 'result' => 'That task no longer exists.', 'subject_type' => null])->and($action->summary)->toBe('Change “Essay”: priority high');
    $this->getJson('/assistant')->assertJsonPath('messages.1.proposals.0.status', 'pending');
});

it('records a suggestion that was turned down', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Nope']]]], 'Suggested.']);
    $id = askAssistant('Add it')->json('messages.1.id');

    $this->postJson("/assistant/messages/{$id}/proposals/0/dismiss")->assertOk();

    expect(lastAction())->toMatchArray(['status' => 'dismissed', 'type' => 'create_task', 'changes' => null])->and(AssistantAction::count())->toBe(1);
});

it('does not record a suggestion that is still waiting', function () {
    fakeOllama([['calls' => [['create_task', ['title' => 'Maybe']]]], 'Suggested.']);
    askAssistant('Add it');

    expect(AssistantAction::count())->toBe(0);
});

it('keeps the record when a new chat is started', function () {
    approve('create_task', ['title' => 'Keep me']);

    $this->deleteJson('/assistant')->assertOk();

    expect(AssistantMessage::count())->toBe(0)->and(AssistantAction::count())->toBe(1)->and(lastAction()->assistant_message_id)->toBeNull();
});

it('lists the user\'s record, newest first, and only theirs', function () {
    AssistantAction::factory()->for(User::factory())->create(['summary' => 'Someone else\'s']);
    AssistantAction::factory()->for($this->user)->create(['summary' => 'First', 'created_at' => now()->subHour()]);
    AssistantAction::factory()->for($this->user)->create(['summary' => 'Second', 'changes' => [['label' => 'Title', 'from' => 'A', 'to' => 'B']]]);

    $this->getJson('/assistant/actions')->assertOk()
        ->assertJsonCount(2, 'actions')
        ->assertJsonPath('actions.0.summary', 'Second')
        ->assertJsonPath('actions.0.changes.0.to', 'B')
        ->assertJsonPath('actions.1.summary', 'First');
});

it('needs a login to see the record', function () {
    auth()->logout();

    $this->getJson('/assistant/actions')->assertUnauthorized();
});

it('lets the assistant answer what happened to a task', function () {
    $this->user->tasks()->create(['title' => 'Essay', 'priority' => 'normal']);
    $this->user->tasks()->create(['title' => 'Call mom', 'priority' => 'normal']);
    fakeOllama([
        ['calls' => [['update_task', ['task' => 'essay', 'priority' => 'high']]]], 'S.',
        ['calls' => [['delete_task', ['task' => 'call mom']]]], 'S.',
        ['calls' => [['list_my_changes', ['about' => 'essay']]]], 'The essay was made high priority.',
    ]);

    foreach (['Essay high priority', 'Delete call mom'] as $text) {
        $id = askAssistant($text)->json('messages.1.id');
        $this->postJson("/assistant/messages/{$id}/proposals/0/approve")->assertOk();
    }
    AssistantAction::factory()->for(User::factory())->create(['summary' => 'Change “Essay” of someone else', 'subject_title' => 'Essay']);

    askAssistant('What happened to my essay task?')->assertJsonPath('messages.1.content', 'The essay was made high priority.');

    $tool = collect(collect(ollamaRequests())->last()['messages'])->where('role', 'tool')->last()['content'];
    expect($tool)->toContain('Priority: normal → high')->toContain('applied')->not->toContain('Call mom')->not->toContain('someone else');
});

it('shows the assistant every recent change when it asks without a name', function () {
    AssistantAction::factory()->for($this->user)->create(['summary' => 'Delete task “Old”', 'status' => 'failed', 'result' => 'That task no longer exists.']);
    fakeOllama([['calls' => [['list_my_changes', []]]], 'One failed.']);

    askAssistant('What did you change?');

    expect(collect(ollamaRequests()[1]['messages'])->firstWhere('role', 'tool')['content'])->toContain('failed')->toContain('That task no longer exists.');
});
