<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Assistant test helpers
|--------------------------------------------------------------------------
|
| A fake Ollama. Loaded from tests/Pest.php.
|
*/

/**
 * Makes Ollama answer with these turns in order. A turn is a string (a plain reply), or an array with optional
 * "content" and "calls": a list of [tool name, arguments].
 *
 * @param  list<string|array{content?: string, calls?: list<array{0: string, 1: array<string, mixed>}>}>  $turns
 */
function fakeOllama(array $turns): void
{
    $sequence = Http::sequence();

    foreach ($turns as $turn) {
        $turn = is_string($turn) ? ['content' => $turn] : $turn;

        $sequence->push(['message' => [
            'role' => 'assistant',
            'content' => $turn['content'] ?? '',
            'tool_calls' => array_map(fn (array $call) => ['function' => ['name' => $call[0], 'arguments' => (object) $call[1]]], $turn['calls'] ?? []),
        ]]);
    }

    Http::fake(['*/api/chat' => $sequence]);
}

function fakeOllamaDown(): void
{
    Http::fake(['*/api/chat' => fn () => throw new ConnectionException('refused')]);
}

/** @return list<array<string, mixed>> the JSON bodies sent to Ollama, in order */
function ollamaRequests(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), '/api/chat'))
        ->map(fn (Request $request) => $request->data())
        ->values()
        ->all();
}

function askAssistant(string $content = 'Hello', string $timezone = 'America/New_York')
{
    return test()->postJson('/assistant', ['content' => $content, 'timezone' => $timezone]);
}
