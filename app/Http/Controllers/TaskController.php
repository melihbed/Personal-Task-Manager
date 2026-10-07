<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
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
     * Change a task's details. Moving the deadline or renaming it also updates its Google Calendar events.
     */
    public function update(UpdateTaskRequest $request, string $task): RedirectResponse
    {
        $record = $request->user()->tasks()->findOrFail($task);
        $validated = $request->validated();

        $record->fill([
            'title' => $validated['title'],
            'notes' => $validated['notes'] ?? null,
            'priority' => $validated['priority'] ?? 'normal',
            'estimate_minutes' => $validated['estimate_minutes'] ?? null,
            'due_at' => isset($validated['due_at']) ? CarbonImmutable::parse($validated['due_at'])->utc() : null,
            'due_has_time' => $validated['due_has_time'] ?? true,
        ]);
        $record->responsibility_id = $validated['responsibility_id'] ?? null;
        $record->save();

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
