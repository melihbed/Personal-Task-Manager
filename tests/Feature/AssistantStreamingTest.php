<?php

use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 18:00:00 UTC');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * One Ollama answer as the line-by-line stream it really sends. Each item is a text piece, or ['tool' => name, 'args' => [...]].
 *
 * @param  list<string|array{tool: string, args: array<string, mixed>}>  $pieces
 */
function ollamaStream(array $pieces): string
{
    $lines = array_map(fn ($piece) => json_encode(['message' => ['role' => 'assistant', 'content' => is_string($piece) ? $piece : '', 'tool_calls' => is_string($piece) ? [] : [['function' => ['name' => $piece['tool'], 'arguments' => (object) $piece['args']]]]], 'done' => false], JSON_UNESCAPED_UNICODE), $pieces);
    $lines[] = json_encode(['message' => ['role' => 'assistant', 'content' => ''], 'done' => true]);

    return implode("\n", $lines)."\n";
}

/** @param  list<string>  $bodies  one raw answer per request to Ollama */
function fakeOllamaStreams(array $bodies): void
{
    $sequence = Http::sequence();

    foreach ($bodies as $body) {
        $sequence->push($body);
    }

    Http::fake(['*/api/chat' => $sequence]);
}

/** @return list<array<string, mixed>> the events of a streamed reply */
function streamEvents(string $content = 'Hello'): array
{
    $response = test()->postJson('/assistant', ['content' => $content, 'timezone' => 'America/New_York', 'stream' => true]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/x-ndjson');

    return collect(explode("\n", trim($response->streamedContent())))->filter()->map(fn (string $line) => json_decode($line, true))->values()->all();
}

it('streams the reply piece by piece, then sends the saved messages', function () {
    fakeOllamaStreams([ollamaStream(['You ', 'have ', 'one task.'])]);

    $events = streamEvents('What is open?');

    expect(collect($events)->where('type', 'delta')->pluck('text')->all())->toBe(['You ', 'have ', 'one task.']);

    $done = collect($events)->last();
    expect($done['type'])->toBe('done')->and($done['messages'][0])->toMatchArray(['role' => 'user', 'content' => 'What is open?'])->and($done['messages'][1])->toMatchArray(['role' => 'assistant', 'content' => 'You have one task.']);
    expect(AssistantMessage::count())->toBe(2);
});

it('says what it is doing while a tool runs, and drops words said before it', function () {
    $this->user->tasks()->create(['title' => 'Write essay', 'priority' => 'normal']);
    fakeOllamaStreams([
        ollamaStream(['Let me check. ', ['tool' => 'list_tasks', 'args' => ['filter' => 'open']]]),
        ollamaStream(['One task: ', 'Write essay.']),
    ]);

    $events = streamEvents();

    expect(array_column($events, 'type'))->toBe(['delta', 'reset', 'status', 'delta', 'delta', 'done'])
        ->and($events[2]['text'])->toBe('Looking at your tasks…')
        ->and(collect($events)->last()['messages'][1]['content'])->toBe('One task: Write essay.');
});

it('has a friendly status for every tool', function (string $tool, array $args, string $status) {
    fakeOllamaStreams([ollamaStream([['tool' => $tool, 'args' => $args]]), ollamaStream(['Ok.'])]);

    expect(collect(streamEvents())->firstWhere('type', 'status')['text'])->toBe($status);
})->with([
    ['get_schedule', ['from_date' => 'today', 'to_date' => 'today'], 'Checking your calendar…'],
    ['list_coursework', [], 'Checking your coursework…'],
    ['list_routines', [], 'Looking at your routines…'],
    ['list_my_changes', [], 'Looking at what changed…'],
    ['create_task', ['title' => 'X'], 'Preparing a suggestion…'],
    ['delete_routine', ['routine' => 'x'], 'Preparing a suggestion…'],
    ['not_a_tool', [], 'Working on it…'],
]);

it('sends suggestions with the finished reply', function () {
    fakeOllamaStreams([ollamaStream([['tool' => 'create_task', 'args' => ['title' => 'Buy a charger']]]), ollamaStream(['I suggest adding it.'])]);

    $done = collect(streamEvents())->last();

    expect($done['messages'][1]['proposals'][0])->toMatchArray(['summary' => 'Add task “Buy a charger”, no deadline', 'status' => 'pending']);
});

it('keeps a long line whole even though it arrives in several reads', function () {
    $long = str_repeat('word ', 900);
    fakeOllamaStreams([ollamaStream([$long, 'The end.'])]);

    $done = collect(streamEvents())->last();

    expect($done['messages'][1]['content'])->toBe(trim($long.'The end.'));
});

it('copes with a last line that has no newline after it', function () {
    Http::fake(['*/api/chat' => Http::response(json_encode(['message' => ['role' => 'assistant', 'content' => 'No newline'], 'done' => true]))]);

    expect(collect(streamEvents())->last()['messages'][1]['content'])->toBe('No newline');
});

it('tells the user when Ollama is not running, and forgets the unanswered message', function () {
    fakeOllamaDown();

    $events = streamEvents();

    expect($events)->toHaveCount(1)->and($events[0]['type'])->toBe('error')->and($events[0]['message'])->toContain('Ollama');
    expect(AssistantMessage::count())->toBe(0);
});

it('reports an error that arrives in the middle of the stream', function () {
    fakeOllamaStreams([json_encode(['message' => ['role' => 'assistant', 'content' => 'Starting']])."\n".json_encode(['error' => 'the model crashed'])."\n"]);

    $events = streamEvents();

    expect(collect($events)->last()['type'])->toBe('error');
    expect(AssistantMessage::count())->toBe(0);
});

it('still answers in one piece unless a stream is asked for', function (array $extra) {
    fakeOllamaStreams([ollamaStream(['All ', 'at once.'])]);

    $this->postJson('/assistant', ['content' => 'Hi', 'timezone' => 'UTC', ...$extra])->assertOk()->assertJsonPath('messages.1.content', 'All at once.');
})->with([[[]], [['stream' => false]]]);

it('does not stream to someone who is not logged in', function () {
    auth()->logout();

    $this->postJson('/assistant', ['content' => 'Hi', 'timezone' => 'UTC', 'stream' => true])->assertUnauthorized();
});

it('checks the message before it starts streaming', function () {
    $this->postJson('/assistant', ['content' => '', 'timezone' => 'UTC', 'stream' => true])->assertUnprocessable();
});
