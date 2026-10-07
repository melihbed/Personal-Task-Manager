<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Talks to a local Ollama server. Nothing leaves the user's machine. */
class OllamaClient
{
    /**
     * One chat turn, without streaming.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{content: string, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}
     */
    public function chat(array $messages, array $tools): array
    {
        $model = config('services.ollama.model');

        try {
            $response = Http::timeout(config('services.ollama.timeout'))
                ->post(config('services.ollama.url').'/api/chat', [
                    'model' => $model,
                    'messages' => $messages,
                    'tools' => $tools,
                    'stream' => false,
                    'keep_alive' => config('services.ollama.keep_alive'),
                    // Only reasoning models accept this; others would refuse the request.
                    ...(str_starts_with((string) $model, 'gpt-oss') && filled(config('services.ollama.think')) ? ['think' => config('services.ollama.think')] : []),
                    // Ollama's default 4096-token window is too small for the tool list plus a day of schedule.
                    'options' => ['temperature' => 0.2, 'num_ctx' => 8192],
                ]);
        } catch (ConnectionException) {
            throw new AssistantUnavailable('The assistant could not reach Ollama. Make sure it is running (open the Ollama app, or run "ollama serve") and try again.');
        }

        if ($response->status() === 404) {
            throw new AssistantUnavailable("Ollama does not have the model \"{$model}\". Run \"ollama pull {$model}\" and try again.");
        }

        if ($response->failed()) {
            throw new AssistantUnavailable('Ollama answered with an error. Please try again.');
        }

        $message = $response->json('message') ?? [];

        return [
            'content' => trim((string) ($message['content'] ?? '')),
            'tool_calls' => collect($message['tool_calls'] ?? [])
                ->map(fn (array $call) => [
                    'name' => (string) ($call['function']['name'] ?? ''),
                    'arguments' => $this->arguments($call['function']['arguments'] ?? []),
                ])
                ->filter(fn (array $call) => $call['name'] !== '')
                ->values()
                ->all(),
        ];
    }

    /**
     * Ollama sends arguments as an object, but some models send a JSON string.
     *
     * @return array<string, mixed>
     */
    private function arguments(mixed $arguments): array
    {
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true);
        }

        return is_array($arguments) ? $arguments : [];
    }
}
