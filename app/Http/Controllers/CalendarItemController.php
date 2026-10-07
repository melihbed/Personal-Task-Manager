<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCalendarItemRequest;
use App\Models\CalendarSession;
use App\Models\Routine;
use App\Models\Task;
use App\Services\GoogleCalendar\EventActionFailed;
use App\Services\GoogleCalendar\GoogleEventManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CalendarItemController extends Controller
{
    /**
     * Adds a task, a Google Calendar event or a routine from a range dragged on the calendar.
     */
    public function store(StoreCalendarItemRequest $request, GoogleEventManager $events): RedirectResponse
    {
        $data = $request->validated();
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        $end = CarbonImmutable::parse($data['ends_at'])->utc();

        if ($start->diffInSeconds($end) > 86400) {
            throw ValidationException::withMessages(['ends_at' => 'Keep it within 24 hours.']);
        }

        $message = match ($data['type']) {
            'task' => $this->task($request, $data, $start, $end),
            'event' => $this->event($request, $events, $data),
            'routine' => $this->routine($request, $data, $start, $end),
        };

        return back()->with('status', $message);
    }

    /**
     * A new task. It can reserve the dragged time as a work session, and take the end of that time as its deadline.
     *
     * @param  array<string, mixed>  $data
     */
    private function task(StoreCalendarItemRequest $request, array $data, CarbonImmutable $start, CarbonImmutable $end): string
    {
        DB::transaction(function () use ($request, $data, $start, $end) {
            // Serialize this user's schedule writes so concurrent saves cannot bypass the overlap warning.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();

            if ($data['reserve'] && ! ($data['allow_overlap'] ?? false)
                && CalendarSession::where('user_id', $request->user()->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['allow_overlap' => 'This overlaps an existing work session. Check “Save despite overlap” to continue.']);
            }

            $task = new Task(['title' => $data['title'], 'priority' => 'normal', 'due_has_time' => true]);
            $task->due_at = ($data['deadline_at_end'] ?? false) ? $end : null;
            $task->user()->associate($request->user());
            $task->responsibility_id = $data['responsibility_id'] ?? null;
            $task->save();

            if ($data['reserve']) {
                $session = new CalendarSession;
                $session->user()->associate($request->user());
                $session->task()->associate($task);
                $session->starts_at = $start;
                $session->ends_at = $end;
                $session->save();
            }
        });

        return $data['reserve'] ? 'Task added with time reserved.' : 'Task added.';
    }

    /**
     * A new event in the user's Google Calendar.
     *
     * @param  array<string, mixed>  $data
     */
    private function event(StoreCalendarItemRequest $request, GoogleEventManager $events, array $data): string
    {
        $account = $request->user()->googleAccount;

        if ($account === null || $account->needs_reconnect) {
            throw ValidationException::withMessages(['type' => 'Connect Google Calendar to add events.']);
        }

        try {
            $events->create($account, $data['calendar_id'] ?? null, $data);
        } catch (EventActionFailed $exception) {
            throw ValidationException::withMessages(['type' => $exception->getMessage()]);
        }

        return 'Added to Google Calendar.';
    }

    /**
     * A new routine that repeats on the chosen weekdays at the dragged time of day, for the dragged length.
     *
     * @param  array<string, mixed>  $data
     */
    private function routine(StoreCalendarItemRequest $request, array $data, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $local = $start->setTimezone($data['timezone']);
        $minutes = (int) $start->diffInMinutes($end);

        if ($minutes < 5) {
            throw ValidationException::withMessages(['ends_at' => 'A routine needs at least 5 minutes.']);
        }

        if (! empty($data['ends_on']) && $data['ends_on'] < $local->toDateString()) {
            throw ValidationException::withMessages(['ends_on' => 'The end date must be on or after the first day.']);
        }

        $routine = new Routine([
            'title' => $data['title'],
            'days' => collect($data['days'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all(),
            'start_time' => $local->format('H:i').':00',
            'duration_minutes' => $minutes,
            'timezone' => $data['timezone'],
            'starts_on' => $local->toDateString(),
            'ends_on' => $data['ends_on'] ?? null,
        ]);
        $routine->user()->associate($request->user());
        $routine->responsibility_id = $data['responsibility_id'] ?? null;
        $routine->save();

        return 'Routine added.';
    }
}
