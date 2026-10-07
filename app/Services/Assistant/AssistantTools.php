<?php

namespace App\Services\Assistant;

use App\Models\CalendarSession;
use App\Models\Task;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEvents;
use Carbon\CarbonImmutable;

/**
 * What the assistant can do. Reading tools run straight away. Changing tools only build a proposal: nothing is
 * created, planned or completed until the user approves it (see ProposalExecutor).
 */
class AssistantTools
{
    private const MAX_DAYS = 14;

    /**
     * The tool list in the format Ollama expects.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $tool = fn (string $name, string $description, array $properties = [], array $required = []) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
            ],
        ];
        $text = fn (string $description) => ['type' => 'string', 'description' => $description];

        return [
            $tool('list_tasks', 'List the user\'s tasks, including assignments from Canvas. Use this to answer what is due, overdue, or still to do.', [
                'filter' => ['type' => 'string', 'enum' => ['open', 'overdue', 'due_soon', 'completed_recently'], 'description' => 'open = not done yet; due_soon = due in the next 48 hours.'],
            ], ['filter']),
            $tool('get_schedule', 'Get what is on the calendar between two dates: planned work sessions, routines, Google Calendar events and task deadlines. Use this to answer when the user is busy or free.', [
                'from_date' => $text('First day: YYYY-MM-DD, today, tomorrow, or a weekday name like friday.'),
                'to_date' => $text('Last day, in the same forms. At most 14 days after from_date. Same as from_date for a single day.'),
            ], ['from_date', 'to_date']),
            $tool('list_coursework', 'List the user\'s Canvas assignments, quizzes and discussions that are not finished, with course, due date and whether they are missing.'),
            $tool('create_task', 'Propose a new task. The user must approve it before it exists.', [
                'title' => $text('Short task title.'),
                'due_date' => $text('Optional deadline day: YYYY-MM-DD, today, tomorrow, or a weekday name like friday or next tuesday.'),
                'due_time' => $text('Optional deadline time, 24-hour HH:MM. Only with due_date.'),
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
            ], ['title']),
            $tool('plan_session', 'Propose reserving calendar time to work on an existing task. The user must approve it.', [
                'task_id' => ['type' => 'integer', 'description' => 'The id from list_tasks.'],
                'date' => $text('Day: YYYY-MM-DD, today, tomorrow, or a weekday name like friday.'),
                'start_time' => $text('Start time, 24-hour HH:MM.'),
                'minutes' => ['type' => 'integer', 'description' => 'Length of the session in minutes, 5 to 720.'],
            ], ['task_id', 'date', 'start_time', 'minutes']),
            $tool('complete_task', 'Propose marking an existing task as done. The user must approve it.', [
                'task_id' => ['type' => 'integer', 'description' => 'The id from list_tasks.'],
            ], ['task_id']),
        ];
    }

    /**
     * Runs one tool call.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{result: array<string, mixed>, proposal: array<string, mixed>|null}
     */
    public function run(User $user, string $timezone, string $name, array $arguments): array
    {
        try {
            return match ($name) {
                'list_tasks' => ['result' => ['tasks' => $this->listTasks($user, $timezone, (string) ($arguments['filter'] ?? 'open'))], 'proposal' => null],
                'get_schedule' => ['result' => ['entries' => $this->schedule($user, $timezone, (string) ($arguments['from_date'] ?? ''), (string) ($arguments['to_date'] ?? ''))], 'proposal' => null],
                'list_coursework' => ['result' => ['coursework' => $this->coursework($user, $timezone)], 'proposal' => null],
                'create_task' => $this->proposal($this->proposeCreateTask($timezone, $arguments)),
                'plan_session' => $this->proposal($this->proposePlanSession($user, $timezone, $arguments)),
                'complete_task' => $this->proposal($this->proposeCompleteTask($user, $timezone, $arguments)),
                default => ['result' => ['error' => "Unknown tool \"{$name}\"."], 'proposal' => null],
            };
        } catch (ProposalFailed $exception) {
            return ['result' => ['error' => $exception->getMessage()], 'proposal' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{result: array<string, mixed>, proposal: array<string, mixed>}
     */
    private function proposal(array $proposal): array
    {
        return [
            'result' => ['status' => 'proposed', 'note' => 'Shown to the user for approval. It has NOT been done yet. Do not say it is done.', 'summary' => $proposal['summary']],
            'proposal' => $proposal,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listTasks(User $user, string $timezone, string $filter): array
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($timezone)->toDateString();
        $tomorrow = $now->setTimezone($timezone)->addDay()->toDateString();

        $tasks = $user->tasks()->with(['responsibility:id,name', 'canvasAssignment.course:id,name'])
            ->withExists(['calendarSessions as planned' => fn ($query) => $query->where('ends_at', '>', $now)])
            ->orderByRaw('due_at is null')->orderBy('due_at')->get();

        $open = $tasks->whereNull('completed_at');

        $selected = match ($filter) {
            'overdue' => $open->filter(fn (Task $task) => $task->due_at !== null && $this->isOverdue($task, $now, $today)),
            'due_soon' => $open->filter(fn (Task $task) => $task->due_at !== null && ! $this->isOverdue($task, $now, $today) && $this->isSoon($task, $now, $timezone, $tomorrow)),
            'completed_recently' => $tasks->filter(fn (Task $task) => $task->completed_at !== null && $task->completed_at >= $now->subDays(7)),
            default => $open,
        };

        return $selected->take(40)->map(fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'due' => $this->due($task, $timezone),
            'priority' => $task->priority,
            'has_time_planned' => (bool) $task->planned,
            'from' => $task->canvasAssignment ? 'Canvas: '.$task->canvasAssignment->course->name : ($task->responsibility?->name ?? 'Inbox'),
            'done' => $task->completed_at !== null,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schedule(User $user, string $timezone, string $from, string $to): array
    {
        $first = $this->day($from, $timezone);
        $last = $this->day($to === '' ? $from : $to, $timezone);

        if ($last < $first) {
            throw new ProposalFailed('to_date must not be before from_date.');
        }

        if ($first->diffInDays($last) >= self::MAX_DAYS) {
            throw new ProposalFailed('Ask for at most '.self::MAX_DAYS.' days at a time.');
        }

        $start = $first->utc();
        $end = $last->addDay()->utc();
        $entries = collect();

        CalendarSession::where('user_id', $user->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->with('task:id,title')->get()
            ->each(fn ($session) => $entries->push($this->entry('work session', $session->task->title, $session->starts_at, $session->ends_at, $timezone)));

        foreach ($user->routines()->with('occurrences')->get() as $routine) {
            foreach ($routine->occurrencesBetween($start, $end) as $occurrence) {
                if (! $occurrence['completed']) {
                    $entries->push($this->entry('routine', $occurrence['title'], CarbonImmutable::parse($occurrence['starts_at']), CarbonImmutable::parse($occurrence['ends_at']), $timezone));
                }
            }
        }

        $user->tasks()->whereNull('completed_at')->whereNotNull('due_at')->where('due_at', '>=', $start)->where('due_at', '<', $end)->get()
            ->each(fn (Task $task) => $entries->push(['type' => 'deadline', 'title' => $task->title, 'start' => $this->due($task, $timezone), 'sort' => $task->due_at->toIso8601String()]));

        foreach (app(GoogleCalendarEvents::class)->between($user, $start, $end) as $event) {
            $entries->push($event['all_day']
                ? ['type' => 'calendar event (all day)', 'title' => $event['title'], 'start' => $event['start_date'], 'sort' => $event['start_date']]
                : $this->entry('calendar event', $event['title'], CarbonImmutable::parse($event['starts_at']), CarbonImmutable::parse($event['ends_at']), $timezone));
        }

        return $entries->sortBy('sort')->take(60)->map(fn (array $entry) => collect($entry)->except('sort')->all())->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function coursework(User $user, string $timezone): array
    {
        return $user->canvasAssignments()->open()
            ->whereHas('course', fn ($course) => $course->where('tracked', true))
            ->with('course:id,name')->orderByRaw('due_at is null')->orderBy('due_at')->limit(40)->get()
            ->map(fn ($assignment) => [
                'name' => $assignment->name,
                'course' => $assignment->course->name,
                'type' => $assignment->kind,
                'due' => $assignment->due_at?->setTimezone($timezone)->format('D M j, g:i A') ?? 'no due date',
                'overdue' => $assignment->due_at !== null && $assignment->due_at->isPast(),
                'marked_missing_in_canvas' => $assignment->missing,
                'points' => $assignment->points_possible,
                'task_id' => $assignment->task_id,
            ])->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposeCreateTask(string $timezone, array $arguments): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 255) {
            throw new ProposalFailed('A task needs a title of up to 255 characters.');
        }

        $date = isset($arguments['due_date']) && $arguments['due_date'] !== '' ? $this->day((string) $arguments['due_date'], $timezone) : null;
        $time = isset($arguments['due_time']) && $arguments['due_time'] !== '' ? $this->time((string) $arguments['due_time']) : null;

        if ($time !== null && $date === null) {
            throw new ProposalFailed('due_time needs a due_date.');
        }

        $priority = in_array($arguments['priority'] ?? 'normal', ['low', 'normal', 'high'], true) ? ($arguments['priority'] ?? 'normal') : 'normal';
        $due = $date === null ? 'no deadline' : 'due '.$date->format('D M j').($time !== null ? ', '.CarbonImmutable::createFromFormat('H:i', $time)->format('g:i A') : '');

        return [
            'type' => 'create_task',
            'args' => ['title' => $title, 'due_date' => $date?->toDateString(), 'due_time' => $time, 'priority' => $priority],
            'summary' => "Add task “{$title}”, {$due}",
            'timezone' => $timezone,
            'status' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposePlanSession(User $user, string $timezone, array $arguments): array
    {
        $task = $this->ownedOpenTask($user, $arguments['task_id'] ?? null);
        $minutes = (int) ($arguments['minutes'] ?? 0);

        if ($minutes < 5 || $minutes > 720) {
            throw new ProposalFailed('A session must be between 5 and 720 minutes.');
        }

        $start = $this->day((string) ($arguments['date'] ?? ''), $timezone)->setTimeFromTimeString($this->time((string) ($arguments['start_time'] ?? '')));
        $end = $start->addMinutes($minutes);

        return [
            'type' => 'plan_session',
            'args' => ['task_id' => $task->id, 'starts_at' => $start->utc()->toIso8601String(), 'ends_at' => $end->utc()->toIso8601String()],
            'summary' => "Plan “{$task->title}” on {$start->format('D M j')}, {$start->format('g:i')}–{$end->format('g:i A')}",
            'timezone' => $timezone,
            'status' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposeCompleteTask(User $user, string $timezone, array $arguments): array
    {
        $task = $this->ownedOpenTask($user, $arguments['task_id'] ?? null);

        return [
            'type' => 'complete_task',
            'args' => ['task_id' => $task->id],
            'summary' => "Mark “{$task->title}” as done",
            'timezone' => $timezone,
            'status' => 'pending',
        ];
    }

    private function ownedOpenTask(User $user, mixed $id): Task
    {
        $task = is_numeric($id) ? $user->tasks()->find((int) $id) : null;

        if ($task === null) {
            throw new ProposalFailed('There is no task with that id. Use list_tasks to find the right id.');
        }

        if ($task->completed_at !== null) {
            throw new ProposalFailed('That task is already done.');
        }

        return $task;
    }

    /**
     * A day the model named: YYYY-MM-DD, "today", "tomorrow", or a weekday name ("friday", "next tuesday"), which the
     * app resolves itself because small models are poor at calendar arithmetic. A bare weekday means the next one
     * counting today; "next" skips today.
     */
    private function day(string $value, string $timezone): CarbonImmutable
    {
        $word = strtolower(trim($value));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $weekdays = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

        if ($word === 'today') {
            return $today;
        }

        if ($word === 'tomorrow') {
            return $today->addDay();
        }

        if (preg_match('/^(next )?(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/', $word, $match) === 1) {
            $ahead = ($weekdays[$match[2]] - $today->dayOfWeekIso + 7) % 7;

            return $today->addDays($ahead === 0 && $match[1] !== '' ? 7 : $ahead);
        }

        $date = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $word, $match) === 1 && checkdate((int) $match[2], (int) $match[3], (int) $match[1])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $word, $timezone)
            : null;

        return $date ?? throw new ProposalFailed("\"{$value}\" is not a date. Use YYYY-MM-DD, today, tomorrow, or a weekday name.");
    }

    private function time(string $value): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) !== 1) {
            throw new ProposalFailed("\"{$value}\" is not a 24-hour time in HH:MM form.");
        }

        return $value;
    }

    private function isOverdue(Task $task, CarbonImmutable $now, string $today): bool
    {
        return $task->due_has_time ? $task->due_at->isBefore($now) : $task->due_at->toDateString() < $today;
    }

    private function isSoon(Task $task, CarbonImmutable $now, string $timezone, string $tomorrow): bool
    {
        return $task->due_has_time ? $task->due_at->lte($now->addHours(48)) : $task->due_at->toDateString() <= $tomorrow;
    }

    private function due(Task $task, string $timezone): ?string
    {
        if ($task->due_at === null) {
            return null;
        }

        return $task->due_has_time ? $task->due_at->setTimezone($timezone)->format('D M j, g:i A') : $task->due_at->format('D M j').' (date only)';
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $type, string $title, CarbonImmutable|\DateTimeInterface $start, CarbonImmutable|\DateTimeInterface $end, string $timezone): array
    {
        $start = CarbonImmutable::instance($start)->setTimezone($timezone);
        $end = CarbonImmutable::instance($end)->setTimezone($timezone);

        return ['type' => $type, 'title' => $title, 'start' => $start->format('D M j, g:i A'), 'end' => $end->format('g:i A'), 'sort' => $start->utc()->toIso8601String()];
    }
}
