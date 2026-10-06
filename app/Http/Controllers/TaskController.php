<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $task = new Task([
            'title' => $validated['title'],
            'priority' => $validated['priority'] ?? 'normal',
            'estimate_minutes' => $validated['estimate_minutes'] ?? null,
            'due_at' => isset($validated['due_at']) ? CarbonImmutable::parse($validated['due_at'])->utc() : null,
            'due_has_time' => $validated['due_has_time'] ?? true,
        ]);
        $task->user()->associate($request->user());
        $task->responsibility_id = $validated['responsibility_id'] ?? null;
        $task->save();

        return back();
    }

    /**
     * Delete a task. Its calendar sessions are removed with it (cascading foreign key).
     */
    public function destroy(Request $request, string $task): RedirectResponse
    {
        $request->user()->tasks()->findOrFail($task)->delete();

        return back();
    }

    /**
     * Complete or reopen a task.
     */
    public function updateCompletion(Request $request, string $task): RedirectResponse
    {
        $validated = $request->validate(['completed' => ['required', 'boolean']]);

        $record = $request->user()->tasks()->findOrFail($task);
        $record->completed_at = $validated['completed'] ? ($record->completed_at ?? now()) : null;
        $record->save();

        return back();
    }
}
