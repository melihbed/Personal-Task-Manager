<?php

use App\Http\Controllers\CalendarSessionController;
use App\Http\Controllers\CompletedTaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\GoogleSyncController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\RoutineOccurrenceController;
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

    Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
    Route::get('/integrations/google', [GoogleCalendarController::class, 'show'])->name('google.show');
    Route::patch('/integrations/google', [GoogleCalendarController::class, 'update'])->name('google.update');
    Route::delete('/integrations/google', [GoogleCalendarController::class, 'destroy'])->name('google.destroy');
    Route::get('/integrations/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/integrations/google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');
    Route::post('/integrations/google/sync', [GoogleSyncController::class, 'store'])->name('google.sync.store');
    Route::delete('/integrations/google/sync', [GoogleSyncController::class, 'destroy'])->name('google.sync.destroy');

    Route::post('/routines', [RoutineController::class, 'store'])->name('routines.store');
    Route::patch('/routines/{routine}', [RoutineController::class, 'update'])->name('routines.update');
    Route::delete('/routines/{routine}', [RoutineController::class, 'destroy'])->name('routines.destroy');
    Route::patch('/routines/{routine}/occurrences/{date}', [RoutineOccurrenceController::class, 'update'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
        ->name('routines.occurrences.update');

    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::delete('/completed-tasks', [CompletedTaskController::class, 'destroy'])->name('completed-tasks.destroy');
    Route::patch('/tasks/{task}/completion', [TaskController::class, 'updateCompletion'])->name('tasks.completion.update');

    Route::post('/tasks/{task}/calendar-sessions', [CalendarSessionController::class, 'store'])->name('calendar-sessions.store');
    Route::delete('/calendar-sessions/{session}', [CalendarSessionController::class, 'destroy'])->name('calendar-sessions.destroy');

    Route::patch('/calendar-sessions/{session}',
        [CalendarSessionController::class, 'update'])->name('calendar-sessions.update');
});
