<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Talks to a local Ollama server. Nothing leaves the user's machine. */
class OllamaClient
{
    /**
     * One chat turn. Ollama streams its answer as one JSON object per line; the text is handed to $onText as it arrives,
     * and the whole turn is returned at the end.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  (callable(string): void)|null  $onText
     * @return array{content: string, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}
     *
     * @throws AssistantUnavailable
     */
    public function chat(array $messages, array $tools, ?callable $onText = null): array
    {
        $model = config('services.ollama.model');

        try {
            $response = Http::timeout(config('services.ollama.timeout'))
                ->withOptions(['stream' => true])
                ->post(config('services.ollama.url').'/api/chat', [
                    'model' => $model,
                    'messages' => $messages,
                    'tools' => $tools,
                    'stream' => true,
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

        $content = '';
        $calls = [];
        $read = function (string $line) use (&$content, &$calls, $onText): void {
            $chunk = json_decode($line, true);

            if (! is_array($chunk)) {
                return;
            }

            if (isset($chunk['error'])) {
                throw new AssistantUnavailable('Ollama answered with an error. Please try again.');
            }

            $text = (string) ($chunk['message']['content'] ?? '');

            if ($text !== '') {
                $content .= $text;
                $onText?->__invoke($text);
            }

            foreach ($chunk['message']['tool_calls'] ?? [] as $call) {
                $name = (string) ($call['function']['name'] ?? '');

                if ($name !== '') {
                    $calls[] = ['name' => $name, 'arguments' => $this->arguments($call['function']['arguments'] ?? [])];
                }
            }
        };

        try {
            $body = $response->toPsrResponse()->getBody();
            $buffer = '';

            while (! $body->eof()) {
                $buffer .= $body->read(1024);

                while (($end = strpos($buffer, "\n")) !== false) {
                    $read(substr($buffer, 0, $end));
                    $buffer = substr($buffer, $end + 1);
                }
            }

            if (trim($buffer) !== '') {
                $read($buffer);
            }
        } catch (\RuntimeException $exception) {
            if ($exception instanceof AssistantUnavailable) {
                throw $exception;
            }

            throw new AssistantUnavailable('The connection to Ollama was interrupted. Please try again.');
        }

        return ['content' => trim($content), 'tool_calls' => $calls];
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
