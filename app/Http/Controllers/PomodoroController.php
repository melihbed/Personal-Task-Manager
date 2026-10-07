<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePomodoroSettingsRequest;
use App\Models\PomodoroSession;
use App\Services\Pomodoro\PomodoroException;
use App\Services\Pomodoro\PomodoroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PomodoroController extends Controller
{
    public function __construct(private readonly PomodoroService $pomodoro) {}

    /** The Focus page. The timer itself talks to the JSON endpoints below. */
    public function show(Request $request): Response
    {
        return Inertia::render('focus/index', [
            'tasks' => $request->user()->tasks()->whereNull('completed_at')->orderByRaw('due_at is null')->orderBy('due_at')->limit(100)->get(['id', 'title']),
            'initialTaskId' => $request->integer('task') ?: null,
        ]);
    }

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->pomodoro->state($request->user()));
    }

    public function stats(Request $request): JsonResponse
    {
        $validated = $request->validate(['timezone' => ['required', 'timezone']]);

        return response()->json($this->pomodoro->stats($request->user(), $validated['timezone']));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:focus,short_break,long_break'],
            'task_id' => ['nullable', 'integer'],
        ]);

        return $this->act($request, fn () => $this->pomodoro->start($request->user(), $validated['kind'], $validated['task_id'] ?? null));
    }

    public function pause(Request $request, string $session): JsonResponse
    {
        return $this->act($request, fn () => $this->pomodoro->pause($this->own($request, $session)));
    }

    public function resume(Request $request, string $session): JsonResponse
    {
        return $this->act($request, fn () => $this->pomodoro->resume($this->own($request, $session)));
    }

    public function complete(Request $request, string $session): JsonResponse
    {
        return $this->act($request, fn () => $this->pomodoro->complete($this->own($request, $session)));
    }

    public function abandon(Request $request, string $session): JsonResponse
    {
        return $this->act($request, fn () => $this->pomodoro->abandon($this->own($request, $session)));
    }

    public function updateSettings(UpdatePomodoroSettingsRequest $request): JsonResponse
    {
        $this->pomodoro->saveSettings($request->user(), $request->validated());

        return response()->json($this->pomodoro->state($request->user()));
    }

    /** Runs a timer action, and answers with the timer's new state, or with why it could not be done. */
    private function act(Request $request, callable $action): JsonResponse
    {
        try {
            $action();
        } catch (PomodoroException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->pomodoro->state($request->user()));
    }

    private function own(Request $request, string $session): PomodoroSession
    {
        return $request->user()->pomodoroSessions()->findOrFail($session);
    }
}
