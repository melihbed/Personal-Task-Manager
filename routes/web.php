<?php

use App\Http\Controllers\CalendarSessionController;
use App\Http\Controllers\DashboardController;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', DashboardController::class)->middleware('auth')->name('home');
Route::inertia('/about', 'about')->name('about');

Route::middleware('auth')->group(function () {
    Route::post('/responsibilities', function (Request $request) {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $request->user()->responsibilities()->create($validated);
        return back();
    })->name('responsibilities.store');

    Route::get('/responsibilities/{responsibility}', function (Request $request, string $responsibility) {
        $record = $request->user()->responsibilities()->findOrFail($responsibility);
        return Inertia::render('responsibilities/show', [
            'responsibility' => $record->only(['id', 'name', 'description']),
            'tasks' => $record->tasks()->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->get(['id', 'title', 'priority', 'due_at', 'completed_at']),
        ]);
    })->name('responsibilities.show');

    Route::post('/responsibilities/{responsibility}/tasks', function (Request $request, string $responsibility) {
        $record = $request->user()->responsibilities()->findOrFail($responsibility);
        abort_if($record->archived_at !== null, 403, 'This responsibility is archived.');
        $validated = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $task = new Task();
        $task->title = $validated['title'];
        $task->priority = 'normal';
        $task->user()->associate($request->user());
        $task->responsibility()->associate($record);
        $task->save();
        return back();
    })->name('responsibilities.tasks.store');

    Route::post('/tasks', function (Request $request) {
        $validated = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $request->user()->tasks()->create(['title' => $validated['title'], 'priority' => 'normal']);
        return back();
    })->name('tasks.store');

    Route::patch('/tasks/{task}/completion', function (Request $request, string $task) {
        $record = $request->user()->tasks()->findOrFail($task);
        $validated = $request->validate(['completed' => ['required', 'boolean']]);
        $record->completed_at = $validated['completed'] ? ($record->completed_at ?? now()) : null;
        $record->save();
        return back();
    })->name('tasks.completion.update');

    Route::post('/tasks/{task}/calendar-sessions', [CalendarSessionController::class, 'store'])->name('calendar-sessions.store');
    Route::delete('/calendar-sessions/{session}', [CalendarSessionController::class, 'destroy'])->name('calendar-sessions.destroy');

    Route::patch('/calendar-sessions/{session}',
    [CalendarSessionController::class, 'update'])->name('calendar-sessions.update');
});
