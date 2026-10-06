<?php

use App\Http\Controllers\CalendarSessionController;
use App\Http\Controllers\CompletedTaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TaskController;
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
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $request->user()->responsibilities()->create($validated);

        return back();
    })->name('responsibilities.store');

    Route::get('/responsibilities/{responsibility}', function (Request $request, string $responsibility) {
        $record = $request->user()->responsibilities()->findOrFail($responsibility);

        return Inertia::render('responsibilities/show', [
            'responsibility' => $record->only(['id', 'name', 'description']),
            'tasks' => $record->tasks()->where('user_id', $request->user()->id)
                ->select(['id', 'title', 'priority', 'estimate_minutes', 'due_at', 'due_has_time', 'completed_at'])
                ->withCount('calendarSessions')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->get(),
        ]);
    })->name('responsibilities.show');

    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');

    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::delete('/completed-tasks', [CompletedTaskController::class, 'destroy'])->name('completed-tasks.destroy');
    Route::patch('/tasks/{task}/completion', [TaskController::class, 'updateCompletion'])->name('tasks.completion.update');

    Route::post('/tasks/{task}/calendar-sessions', [CalendarSessionController::class, 'store'])->name('calendar-sessions.store');
    Route::delete('/calendar-sessions/{session}', [CalendarSessionController::class, 'destroy'])->name('calendar-sessions.destroy');

    Route::patch('/calendar-sessions/{session}',
        [CalendarSessionController::class, 'update'])->name('calendar-sessions.update');
});
