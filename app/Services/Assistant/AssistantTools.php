<?php

namespace App\Services\Assistant;

use App\Models\CalendarSession;
use App\Models\Routine;
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
            $tool('list_routines', 'List the user\'s repeating routines (things they do on certain weekdays), with their ids.'),
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
            $tool('update_task', 'Propose changing an existing task: its title, deadline, priority or notes. Pass only what changes. The user must approve it.', [
                'task_id' => ['type' => 'integer', 'description' => 'The id from list_tasks.'],
                'title' => $text('New title.'),
                'due_date' => $text('New deadline day: YYYY-MM-DD, today, tomorrow, a weekday name, or "none" to remove the deadline.'),
                'due_time' => $text('New deadline time, 24-hour HH:MM. Only with due_date.'),
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                'notes' => $text('New notes for the task.'),
            ], ['task_id']),
            $tool('delete_task', 'Propose deleting a task and its planned sessions. Only when the user clearly asks to delete or remove it. The user must approve it.', [
                'task_id' => ['type' => 'integer', 'description' => 'The id from list_tasks.'],
            ], ['task_id']),
            $tool('create_routine', 'Propose a new repeating routine, such as "gym on Monday, Wednesday and Friday at 6 PM". The user must approve it.', [
                'title' => $text('Short routine name.'),
                'days' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Weekday names such as ["monday","wednesday"], or "weekdays", "weekends" or "daily".'],
                'start_time' => $text('Start time, 24-hour HH:MM.'),
                'minutes' => ['type' => 'integer', 'description' => 'How long each occurrence lasts, 5 to 1440.'],
                'start_date' => $text('First day it applies. Defaults to today.'),
                'end_date' => $text('Optional last day it applies.'),
            ], ['title', 'days', 'start_time', 'minutes']),
            $tool('update_routine', 'Propose changing an existing routine. Pass only what changes. The user must approve it.', [
                'routine_id' => ['type' => 'integer', 'description' => 'The id from list_routines.'],
                'title' => $text('New name.'),
                'days' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The new full set of weekdays.'],
                'start_time' => $text('New start time, 24-hour HH:MM.'),
                'minutes' => ['type' => 'integer', 'description' => 'New length in minutes.'],
                'end_date' => $text('New last day, or "none" to repeat with no end.'),
            ], ['routine_id']),
            $tool('delete_routine', 'Propose deleting a routine. Only when the user clearly asks to delete or remove it. The user must approve it.', [
                'routine_id' => ['type' => 'integer', 'description' => 'The id from list_routines.'],
            ], ['routine_id']),
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
                'list_routines' => ['result' => ['routines' => $this->listRoutines($user)], 'proposal' => null],
                'update_task' => $this->proposal($this->proposeUpdateTask($user, $timezone, $arguments)),
                'delete_task' => $this->proposal($this->proposeDeleteTask($user, $timezone, $arguments)),
                'create_routine' => $this->proposal($this->proposeCreateRoutine($timezone, $arguments)),
                'update_routine' => $this->proposal($this->proposeUpdateRoutine($user, $timezone, $arguments)),
                'delete_routine' => $this->proposal($this->proposeDeleteRoutine($user, $timezone, $arguments)),
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

    /**
     * @return list<array<string, mixed>>
     */
    private function listRoutines(User $user): array
    {
        return $user->routines()->orderBy('title')->get()->map(fn (Routine $routine) => [
            'id' => $routine->id,
            'title' => $routine->title,
            'days' => $this->dayNames($routine->days),
            'start_time' => CarbonImmutable::createFromFormat('H:i:s', $routine->start_time)->format('g:i A').' ('.$routine->timezone.')',
            'minutes' => $routine->duration_minutes,
            'from' => $routine->starts_on->format('D M j, Y'),
            'until' => $routine->ends_on?->format('D M j, Y') ?? 'no end',
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposeUpdateTask(User $user, string $timezone, array $arguments): array
    {
        $task = $this->ownedTask($user, $arguments['task_id'] ?? null);
        $changes = [];
        $parts = [];

        if (isset($arguments['title']) && trim((string) $arguments['title']) !== '') {
            $title = trim((string) $arguments['title']);

            if (mb_strlen($title) > 255) {
                throw new ProposalFailed('A task title can be up to 255 characters.');
            }

            $changes['title'] = $title;
            $parts[] = "title to “{$title}”";
        }

        if (isset($arguments['due_date']) && $arguments['due_date'] !== '') {
            if (strtolower(trim((string) $arguments['due_date'])) === 'none') {
                $changes['due_date'] = null;
                $parts[] = 'no deadline';
            } else {
                $date = $this->day((string) $arguments['due_date'], $timezone);
                $time = isset($arguments['due_time']) && $arguments['due_time'] !== '' ? $this->time((string) $arguments['due_time']) : null;
                $changes['due_date'] = $date->toDateString();
                $changes['due_time'] = $time;
                $parts[] = 'deadline '.$date->format('D M j').($time !== null ? ', '.CarbonImmutable::createFromFormat('H:i', $time)->format('g:i A') : '');
            }
        } elseif (isset($arguments['due_time']) && $arguments['due_time'] !== '') {
            throw new ProposalFailed('due_time needs a due_date.');
        }

        if (isset($arguments['priority'])) {
            if (! in_array($arguments['priority'], ['low', 'normal', 'high'], true)) {
                throw new ProposalFailed('priority must be low, normal or high.');
            }

            $changes['priority'] = $arguments['priority'];
            $parts[] = "priority {$arguments['priority']}";
        }

        if (isset($arguments['notes'])) {
            $changes['notes'] = trim((string) $arguments['notes']);
            $parts[] = 'notes';
        }

        if ($changes === []) {
            throw new ProposalFailed('Nothing to change. Pass at least one of title, due_date, priority or notes.');
        }

        return [
            'type' => 'update_task',
            'args' => ['task_id' => $task->id, 'changes' => $changes],
            'summary' => "Change “{$task->title}”: ".implode(', ', $parts),
            'timezone' => $timezone,
            'status' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposeDeleteTask(User $user, string $timezone, array $arguments): array
    {
        $task = $this->ownedTask($user, $arguments['task_id'] ?? null);
        $sessions = $task->calendarSessions()->count();

        return [
            'type' => 'delete_task',
            'args' => ['task_id' => $task->id],
            'summary' => "Delete task “{$task->title}”".($sessions > 0 ? " and its {$sessions} planned ".($sessions === 1 ? 'session' : 'sessions') : ''),
            'timezone' => $timezone,
            'status' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposeCreateRoutine(string $timezone, array $arguments): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 255) {
            throw new ProposalFailed('A routine needs a title of up to 255 characters.');
        }

        $days = $this->weekdays($arguments['days'] ?? null);
        $time = $this->time((string) ($arguments['start_time'] ?? ''));
        $minutes = $this->minutes($arguments['minutes'] ?? null);
        $starts = isset($arguments['start_date']) && $arguments['start_date'] !== '' ? $this->day((string) $arguments['start_date'], $timezone) : CarbonImmutable::now($timezone)->startOfDay();
        $ends = isset($arguments['end_date']) && $arguments['end_date'] !== '' && strtolower((string) $arguments['end_date']) !== 'none' ? $this->day((string) $arguments['end_date'], $timezone) : null;

        if ($ends !== null && $ends < $starts) {
            throw new ProposalFailed('end_date must not be before start_date.');
        }

        return [
            'type' => 'create_routine',
            'args' => ['title' => $title, 'days' => $days, 'start_time' => $time, 'minutes' => $minutes, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends?->toDateString()],
            'summary' => "Add routine “{$title}” every ".$this->dayList($days).' at '.CarbonImmutable::createFromFormat('H:i', $time)->format('g:i A')." for {$minutes} minutes",
            'timezone' => $timezone,
            'status' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposeUpdateRoutine(User $user, string $timezone, array $arguments): array
    {
        $routine = $this->ownedRoutine($user, $arguments['routine_id'] ?? null);
        $changes = [];
        $parts = [];

        if (isset($arguments['title']) && trim((string) $arguments['title']) !== '') {
            $changes['title'] = trim((string) $arguments['title']);
            $parts[] = "name to “{$changes['title']}”";
        }

        if (isset($arguments['days']) && $arguments['days'] !== []) {
            $changes['days'] = $this->weekdays($arguments['days']);
            $parts[] = 'days to '.$this->dayList($changes['days']);
        }

        if (isset($arguments['start_time']) && $arguments['start_time'] !== '') {
            $changes['start_time'] = $this->time((string) $arguments['start_time']);
            $parts[] = 'start '.CarbonImmutable::createFromFormat('H:i', $changes['start_time'])->format('g:i A');
        }

        if (isset($arguments['minutes'])) {
            $changes['minutes'] = $this->minutes($arguments['minutes']);
            $parts[] = "length {$changes['minutes']} minutes";
        }

        if (isset($arguments['end_date']) && $arguments['end_date'] !== '') {
            if (strtolower((string) $arguments['end_date']) === 'none') {
                $changes['ends_on'] = null;
                $parts[] = 'no end date';
            } else {
                $end = $this->day((string) $arguments['end_date'], $timezone);

                if ($end->toDateString() < $routine->starts_on->toDateString()) {
                    throw new ProposalFailed('end_date must not be before the routine starts.');
                }

                $changes['ends_on'] = $end->toDateString();
                $parts[] = 'last day '.$end->format('D M j');
            }
        }

        if ($changes === []) {
            throw new ProposalFailed('Nothing to change. Pass at least one of title, days, start_time, minutes or end_date.');
        }

        return [
            'type' => 'update_routine',
            'args' => ['routine_id' => $routine->id, 'changes' => $changes],
            'summary' => "Change routine “{$routine->title}”: ".implode(', ', $parts),
            'timezone' => $timezone,
            'status' => 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposeDeleteRoutine(User $user, string $timezone, array $arguments): array
    {
        $routine = $this->ownedRoutine($user, $arguments['routine_id'] ?? null);

        return [
            'type' => 'delete_routine',
            'args' => ['routine_id' => $routine->id],
            'summary' => "Delete routine “{$routine->title}” ({$this->dayList($routine->days)})",
            'timezone' => $timezone,
            'status' => 'pending',
        ];
    }

    private function ownedTask(User $user, mixed $id): Task
    {
        return (is_numeric($id) ? $user->tasks()->find((int) $id) : null)
            ?? throw new ProposalFailed('There is no task with that id. Use list_tasks to find the right id.');
    }

    private function ownedRoutine(User $user, mixed $id): Routine
    {
        return (is_numeric($id) ? $user->routines()->find((int) $id) : null)
            ?? throw new ProposalFailed('There is no routine with that id. Use list_routines to find the right id.');
    }

    /**
     * Weekday names, "weekdays", "weekends" or "daily" (or ISO numbers 1 to 7), as sorted ISO weekday numbers.
     *
     * @return list<int>
     */
    private function weekdays(mixed $value): array
    {
        $names = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
        $items = is_array($value) ? $value : (is_string($value) && $value !== '' ? preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) : []);
        $days = [];

        foreach ($items as $item) {
            $word = strtolower(trim((string) $item));

            if (in_array($word, ['daily', 'everyday', 'every day', 'all'], true)) {
                $days = [...$days, 1, 2, 3, 4, 5, 6, 7];
            } elseif ($word === 'weekdays') {
                $days = [...$days, 1, 2, 3, 4, 5];
            } elseif ($word === 'weekends') {
                $days = [...$days, 6, 7];
            } elseif (ctype_digit($word) && (int) $word >= 1 && (int) $word <= 7) {
                $days[] = (int) $word;
            } elseif (isset($names[substr($word, 0, 3)]) && strlen($word) >= 3) {
                $days[] = $names[substr($word, 0, 3)];
            } else {
                throw new ProposalFailed("\"{$item}\" is not a weekday. Use names like monday, or weekdays, weekends, daily.");
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? throw new ProposalFailed('Choose at least one day.') : $days;
    }

    private function minutes(mixed $value): int
    {
        $minutes = is_numeric($value) ? (int) $value : 0;

        return $minutes >= 5 && $minutes <= 1440 ? $minutes : throw new ProposalFailed('minutes must be between 5 and 1440.');
    }

    /**
     * @param  list<int>  $days
     * @return list<string>
     */
    private function dayNames(array $days): array
    {
        return array_map(fn (int $day) => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'][$day], $days);
    }

    /**
     * @param  list<int>  $days
     */
    private function dayList(array $days): string
    {
        return match (true) {
            $days === [1, 2, 3, 4, 5, 6, 7] => 'day',
            $days === [1, 2, 3, 4, 5] => 'weekday',
            $days === [6, 7] => 'weekend day',
            default => implode(', ', $this->dayNames($days)),
        };
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
