<?php

namespace App\Http\Controllers;

use App\Models\CalendarSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'week' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone'],
        ]);
        $timezone = $validated['timezone'] ?? 'America/New_York';
        $start = isset($validated['week'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['week'], $timezone)
            : CarbonImmutable::now($timezone);
        $start = $start->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $end = $start->addWeek();
        $user = $request->user();
        $responsibilities = $user->responsibilities()->whereNull('archived_at')
            ->orderBy('name')->get(['id', 'name', 'description', 'color']);
        $tasks = $user->tasks()->select(['id', 'responsibility_id', 'title', 'priority', 'due_at', 'completed_at'])
            ->where(function ($query) use ($user) {
                $query->whereNull('responsibility_id')->orWhereHas('responsibility', function ($responsibility) use ($user) {
                    $responsibility->where('user_id', $user->id)->whereNull('archived_at');
                });
            })
            ->withCount('calendarSessions')->orderByDesc('created_at')->orderByDesc('id')
            ->get();
        $sessions = CalendarSession::where('user_id', $user->id) // finds the specific users sessions
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc()) // The session starts before the week ends.
                                                                                        // The session ends after the week starts.
            ->whereHas('task', fn ($query) => $query->where('user_id', $user->id))      // Check that its task belongs to this user too
            ->with(['task:id,title,responsibility_id,completed_at', 'task.responsibility:id,name,color'])
            ->orderBy('starts_at') // sort earliest first
            ->get() // execute the query
            ->map(fn ($session) => [ // map-> transform each result into an array for react
                'id' => $session->id,
                'task_id' => $session->task_id,
                'title' => $session->task->title,
                'responsibility_name' => $session->task->responsibility?->name ?? 'Inbox',
                'color' => $session->task->responsibility?->color,
                'completed' => $session->task->completed_at !== null,
                'starts_at' => $session->starts_at->utc()->toIso8601String(),
                'ends_at' => $session->ends_at->utc()->toIso8601String(),
            ]);

        return Inertia::render('welcome', [
            'name' => $user->name,
            'email' => $user->email,
            'responsibilities' => $responsibilities,
            'tasks' => $tasks,
            'sessions' => $sessions,
            'weekStart' => $start->format('Y-m-d'),
            'timezone' => $timezone,
        ]);
    }
}
