<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Models\CalendarSession;
use App\Models\RoutineOccurrence;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEvents;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $tasks = $user->tasks()->select(['id', 'responsibility_id', 'title', 'notes', 'priority', 'estimate_minutes', 'due_at', 'due_has_time', 'completed_at'])
            ->where(function ($query) use ($user) {
                $query->whereNull('responsibility_id')->orWhereHas('responsibility', function ($responsibility) use ($user) {
                    $responsibility->where('user_id', $user->id)->whereNull('archived_at');
                });
            })
            ->with('canvasAssignment:id,task_id,html_url,canvas_course_id', 'canvasAssignment.course:id,name')
            ->withCount(['calendarSessions', 'pomodoroSessions as focus_rounds_count' => fn ($query) => $query->where('kind', 'focus')->where('status', 'completed')])
            // The start of the task's next session that has not ended yet; a planned task is being dealt with.
            ->withMin(['calendarSessions as next_session_at' => fn ($query) => $query->where('ends_at', '>', now())], 'starts_at')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->each(fn ($task) => $task->next_session_at = $task->next_session_at
                ? CarbonImmutable::parse($task->next_session_at, 'UTC')->utc()->toIso8601String()
                : null);
        $sessions = $this->sessions($user, $start, $end);

        // The Today panel always shows the real today, whichever week the calendar is on.
        $todayStart = CarbonImmutable::now($timezone)->startOfDay();
        $todayEnd = $todayStart->addDay();
        $todaySessions = $this->sessions($user, $todayStart, $todayEnd);

        $routines = $this->routines($user, $start, $end, $timezone);

        return Inertia::render('welcome', [
            'google' => [
                'connected' => $user->googleAccount !== null,
                'needsReconnect' => (bool) $user->googleAccount?->needs_reconnect,
            ],
            // Google is called after the page appears, so a slow answer never delays the planner.
            'googleEvents' => filled($user->googleAccount?->import_calendar_ids) && ! $user->googleAccount->needs_reconnect
                ? Inertia::defer(fn () => app(GoogleCalendarEvents::class)->between($user, $start->utc(), $end->utc()))
                : [],
            // Today's Google events. On the current week the calendar's own events already cover today, so this is null.
            'googleToday' => match (true) {
                blank($user->googleAccount?->import_calendar_ids) || $user->googleAccount->needs_reconnect => [],
                $todayStart >= $start && $todayStart < $end => null,
                default => Inertia::defer(fn () => app(GoogleCalendarEvents::class)->between($user, $todayStart->utc(), $todayEnd->utc())),
            },
            'routines' => $routines['routines'],
            'routineSessions' => $routines['week'],
            'routinesToday' => $routines['today'],
            'name' => $user->name,
            'email' => $user->email,
            'responsibilities' => $responsibilities,
            'tasks' => $tasks,
            'events' => $this->events($user, $start, $end, $timezone),
            'todayEvents' => $this->events($user, $todayStart, $todayEnd, $timezone),
            'sessions' => $sessions,
            'todaySessions' => $todaySessions->values()->all(),
            'weekStart' => $start->format('Y-m-d'),
            'timezone' => $timezone,
        ]);
    }

    /**
     * The user's own events that overlap a window. A timed event overlaps by its instants; an all-day event by its days in
     * the calendar's timezone.
     *
     * @return list<array<string, mixed>>
     */
    private function events(User $user, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $firstDay = $from->setTimezone($timezone)->toDateString();
        $lastDay = $to->setTimezone($timezone)->subSecond()->toDateString();

        return $user->calendarEvents()
            ->where(fn ($query) => $query
                ->where(fn ($timed) => $timed->where('all_day', false)->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc()))
                ->orWhere(fn ($allDay) => $allDay->where('all_day', true)->where('starts_on', '<=', $lastDay)->where('ends_on', '>=', $firstDay)))
            ->orderBy('starts_at')->orderBy('starts_on')
            ->get()
            ->map(fn (CalendarEvent $event) => $event->toPlanner())
            ->values()
            ->all();
    }

    /**
     * The user's work sessions that overlap a window, earliest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sessions(User $user, CarbonImmutable $from, CarbonImmutable $to)
    {
        return CalendarSession::where('user_id', $user->id)
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())
            ->whereHas('task', fn ($query) => $query->where('user_id', $user->id))
            ->with(['task:id,title,responsibility_id,completed_at', 'task.responsibility:id,name,color'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($session) => [
                'id' => $session->id,
                'task_id' => $session->task_id,
                'title' => $session->task->title,
                'responsibility_name' => $session->task->responsibility?->name ?? 'Inbox',
                'color' => $session->task->responsibility?->color,
                'completed' => $session->task->completed_at !== null,
                'starts_at' => $session->starts_at->utc()->toIso8601String(),
                'ends_at' => $session->ends_at->utc()->toIso8601String(),
            ]);
    }

    /**
     * The user's routines, and their occurrences for the visible week and for today.
     *
     * @return array{routines: list<array<string, mixed>>, week: list<array<string, mixed>>, today: list<array<string, mixed>>}
     */
    private function routines(User $user, CarbonImmutable $weekStart, CarbonImmutable $weekEnd, string $timezone): array
    {
        $todayStart = CarbonImmutable::now($timezone)->startOfDay();
        $todayEnd = $todayStart->addDay();
        $from = $weekStart->min($todayStart)->utc();
        $to = $weekEnd->max($todayEnd)->utc();

        $routines = $user->routines()
            ->where(function ($query) {
                $query->whereNull('responsibility_id')->orWhereHas('responsibility', function ($responsibility) {
                    $responsibility->whereNull('archived_at');
                });
            })
            ->with([
                'responsibility:id,name,color',
                // Exceptions near the window, plus any moved into it from further away.
                'occurrences' => fn ($query) => $query->where(function ($inner) use ($from, $to) {
                    $inner->whereBetween('occurs_on', [$from->subDays(8)->toDateString(), $to->addDays(8)->toDateString()])
                        ->orWhere(fn ($moved) => $moved->where('starts_at', '<', $to)->where('ends_at', '>', $from));
                }),
            ])
            ->orderBy('title')
            ->get();

        $skipped = RoutineOccurrence::whereIn('routine_id', $routines->modelKeys())
            ->where('skipped', true)
            ->where('occurs_on', '>=', $todayStart->toDateString())
            ->orderBy('occurs_on')
            ->get()
            ->groupBy('routine_id');

        $expand = fn (CarbonImmutable $windowStart, CarbonImmutable $windowEnd) => $routines
            ->flatMap(fn ($routine) => collect($routine->occurrencesBetween($windowStart, $windowEnd))->map(fn (array $occurrence) => [
                ...$occurrence,
                'responsibility_name' => $routine->responsibility?->name ?? 'Inbox',
                'color' => $routine->responsibility?->color,
            ]))
            ->sortBy('starts_at')
            ->values()
            ->all();

        return [
            'routines' => $routines->map(fn ($routine) => [
                'id' => $routine->id,
                'title' => $routine->title,
                'responsibility_id' => $routine->responsibility_id,
                'days' => $routine->days,
                'start_time' => substr($routine->start_time, 0, 5),
                'duration_minutes' => $routine->duration_minutes,
                'timezone' => $routine->timezone,
                'starts_on' => $routine->starts_on->toDateString(),
                'ends_on' => $routine->ends_on?->toDateString(),
                'skipped_dates' => ($skipped->get($routine->id) ?? collect())->map(fn ($occurrence) => $occurrence->occurs_on->toDateString())->values()->all(),
            ])->values()->all(),
            'week' => $expand($weekStart->utc(), $weekEnd->utc()),
            'today' => $expand($todayStart->utc(), $todayEnd->utc()),
        ];
    }
}
