<?php

namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Services\Assistant\AssistantRunner;
use App\Services\Assistant\AssistantUnavailable;
use App\Services\Assistant\ProposalExecutor;
use App\Services\Assistant\ProposalFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    /** The user's current conversation. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'messages' => $request->user()->assistantMessages()->latest('id')->limit(60)->get()->reverse()->map(fn (AssistantMessage $message) => $this->present($message))->values(),
        ]);
    }

    /** Sends a message and returns the assistant's reply. */
    public function store(Request $request, AssistantRunner $runner): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'timezone' => ['required', 'timezone'],
        ]);

        try {
            $reply = $runner->reply($request->user(), trim($validated['content']), $validated['timezone']);
        } catch (AssistantUnavailable $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['messages' => [$this->present($reply['user']), $this->present($reply['assistant'])]]);
    }

    /** Makes a suggested change, now that the user approved it. */
    public function approve(Request $request, ProposalExecutor $executor, string $message, int $index): JsonResponse
    {
        $record = $request->user()->assistantMessages()->findOrFail($message);
        $proposals = $record->proposals ?? [];

        abort_unless(isset($proposals[$index]) && $proposals[$index]['status'] === 'pending', 404);

        try {
            $proposals[$index]['result'] = $executor->execute($request->user(), $proposals[$index]);
        } catch (ProposalFailed $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $proposals[$index]['status'] = 'approved';
        $record->update(['proposals' => $proposals]);

        return response()->json(['message' => $this->present($record)]);
    }

    /** Turns a suggested change down. */
    public function dismiss(Request $request, string $message, int $index): JsonResponse
    {
        $record = $request->user()->assistantMessages()->findOrFail($message);
        $proposals = $record->proposals ?? [];

        abort_unless(isset($proposals[$index]) && $proposals[$index]['status'] === 'pending', 404);

        $proposals[$index]['status'] = 'dismissed';
        $record->update(['proposals' => $proposals]);

        return response()->json(['message' => $this->present($record)]);
    }

    /** Starts a new chat. */
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
                'status' => $proposal['status'],
                'result' => $proposal['result'] ?? null,
            ])->values()->all(),
        ];
    }
}
