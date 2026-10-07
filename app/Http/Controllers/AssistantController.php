<?php

namespace App\Http\Controllers;

use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Services\Assistant\ActionLog;
use App\Services\Assistant\AssistantRunner;
use App\Services\Assistant\AssistantUnavailable;
use App\Services\Assistant\ProposalExecutor;
use App\Services\Assistant\ProposalFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantController extends Controller
{
    /** The user's current conversation. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'messages' => $request->user()->assistantMessages()->latest('id')->limit(60)->get()->reverse()->map(fn (AssistantMessage $message) => $this->present($message))->values(),
        ]);
    }

    /**
     * Sends a message. With stream=true the reply comes as it is written, one JSON object per line (status, delta, reset,
     * then done or error); otherwise the finished reply comes as one JSON answer.
     */
    public function store(Request $request, AssistantRunner $runner): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'timezone' => ['required', 'timezone'],
            'stream' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $content = trim($validated['content']);

        if (! $request->boolean('stream')) {
            try {
                $reply = $runner->reply($user, $content, $validated['timezone']);
            } catch (AssistantUnavailable $exception) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }

            return response()->json(['messages' => [$this->present($reply['user']), $this->present($reply['assistant'])]]);
        }

        return response()->stream(function () use ($runner, $user, $content, $validated) {
            $send = function (array $event) {
                echo json_encode($event, JSON_UNESCAPED_UNICODE)."\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            try {
                $reply = $runner->reply($user, $content, $validated['timezone'], $send);
                $send(['type' => 'done', 'messages' => [$this->present($reply['user']), $this->present($reply['assistant'])]]);
            } catch (AssistantUnavailable $exception) {
                $send(['type' => 'error', 'message' => $exception->getMessage()]);
            }
        }, 200, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }

    /** Makes a suggested change, now that the user approved it. */
    public function approve(Request $request, ProposalExecutor $executor, string $message, int $index): JsonResponse
    {
        $record = $request->user()->assistantMessages()->findOrFail($message);
        $proposals = $record->proposals ?? [];

        abort_unless(isset($proposals[$index]) && $proposals[$index]['status'] === 'pending', 404);

        try {
            $proposals[$index]['result'] = $executor->execute($request->user(), $proposals[$index], $record);
        } catch (ProposalFailed $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $proposals[$index]['status'] = 'approved';
        $record->update(['proposals' => $proposals]);

        return response()->json(['message' => $this->present($record)]);
    }

    /** Turns a suggested change down. */
    public function dismiss(Request $request, ActionLog $log, string $message, int $index): JsonResponse
    {
        $record = $request->user()->assistantMessages()->findOrFail($message);
        $proposals = $record->proposals ?? [];

        abort_unless(isset($proposals[$index]) && $proposals[$index]['status'] === 'pending', 404);

        $proposals[$index]['status'] = 'dismissed';
        $record->update(['proposals' => $proposals]);
        $log->record($request->user(), $record, $proposals[$index], AssistantAction::DISMISSED);

        return response()->json(['message' => $this->present($record)]);
    }

    /** What the assistant has done for the user, newest first, so they can see what changed and from what. */
    public function actions(Request $request): JsonResponse
    {
        return response()->json([
            'actions' => $request->user()->assistantActions()->latest('id')->limit(50)->get()->map(fn (AssistantAction $action) => [
                'id' => $action->id,
                'type' => $action->type,
                'status' => $action->status,
                'summary' => $action->summary,
                'result' => $action->result,
                'subject_title' => $action->subject_title,
                'changes' => $action->changes ?? [],
                'at' => $action->created_at->utc()->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Starts a new chat. The record of what changed is kept. */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->assistantMessages()->delete();

        return response()->json(['messages' => []]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AssistantMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'proposals' => collect($message->proposals ?? [])->map(fn (array $proposal) => [
                'summary' => $proposal['summary'],
                // A deletion cannot be taken back, so the panel gives it a warning-coloured button.
                'destructive' => str_starts_with($proposal['type'], 'delete_'),
                'status' => $proposal['status'],
                'result' => $proposal['result'] ?? null,
            ])->values()->all(),
        ];
    }
}
