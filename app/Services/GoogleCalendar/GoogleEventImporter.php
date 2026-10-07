<?php

namespace App\Services\GoogleCalendar;

use App\Models\CalendarEvent;
use App\Models\CalendarSession;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Copies a Google event into the planner as an event, a task, a work session or a routine, once. The copy is the
 * user's own from then on. Each copy is recorded so the Google event is hidden from the preview and is never copied
 * twice. Nothing is ever sent back to Google.
 */
class GoogleEventImporter
{
    private const MAX_MINUTES = 1440;

    /**
     * An event of the app's own, at the Google event's time or on its days, with its place and description.
     *
     * @param  array<string, mixed>  $event
     */
    public function asEvent(User $user, string $calendarId, array $event, ?int $responsibilityId): CalendarEvent
    {
        $allDay = isset($event['start']['date']);

        return DB::transaction(function () use ($user, $calendarId, $event, $responsibilityId, $allDay) {
            $record = new CalendarEvent([
                'title' => $this->title($event),
                'location' => filled($event['location'] ?? null) ? mb_substr(trim($event['location']), 0, 255) : null,
                'notes' => filled($event['description'] ?? null) ? mb_substr(trim(strip_tags((string) $event['description'])), 0, 5000) ?: null : null,
                'all_day' => $allDay,
                'starts_at' => $allDay ? null : $this->instant($event['start']),
                'ends_at' => $allDay ? null : $this->instant($event['end']),
                // Google's all-day end date is exclusive; the app keeps the last day itself.
                'starts_on' => $allDay ? $event['start']['date'] : null,
                'ends_on' => $allDay ? max($event['start']['date'], CarbonImmutable::parse($event['end']['date'])->subDay()->toDateString()) : null,
            ]);
            $record->user()->associate($user);
            $record->responsibility_id = $responsibilityId;
            $record->save();

            $this->remember($user, $calendarId, $event['id'], 'event', $record->id);

            return $record;
        });
    }

    /**
     * A task whose deadline is the event's time: a timed deadline for a timed event, a date-only deadline for an
     * all-day event. A timed event's length becomes the task's duration.
     *
     * @param  array<string, mixed>  $event
     */
    public function asTask(User $user, string $calendarId, array $event, ?int $responsibilityId): Task
    {
        $allDay = isset($event['start']['date']);
        $start = $allDay ? CarbonImmutable::parse($event['start']['date'].' 12:00:00', 'UTC') : $this->instant($event['start']);
        $minutes = $allDay ? null : max(1, min($this->minutes($event), self::MAX_MINUTES));

        return DB::transaction(function () use ($user, $calendarId, $event, $responsibilityId, $allDay, $start, $minutes) {
            $task = Task::withoutEvents(function () use ($user, $event, $responsibilityId, $allDay, $start, $minutes) {
                $task = new Task([
                    'title' => $this->title($event),
                    'priority' => 'normal',
                    'estimate_minutes' => $minutes,
                    'due_at' => $start,
                    'due_has_time' => ! $allDay,
                ]);
                $task->user()->associate($user);
                $task->responsibility_id = $responsibilityId;
                $task->save();

                return $task;
            });

            $this->remember($user, $calendarId, $event['id'], 'task', $task->id);

            return $task;
        });
    }

    /**
     * A work session at the event's time, with a task of the same name to hold it.
     *
     * @param  array<string, mixed>  $event
     *
     * @throws ImportNotPossible
     */
    public function asSession(User $user, string $calendarId, array $event, ?int $responsibilityId): CalendarSession
    {
        if (isset($event['start']['date'])) {
            throw new ImportNotPossible('An all-day event has no time to plan. Add it as a task instead.');
        }

        $start = $this->instant($event['start']);
        $end = $this->instant($event['end']);

        if ($this->minutes($event) > self::MAX_MINUTES) {
            throw new ImportNotPossible('This event is longer than 24 hours, which is too long for a work session.');
        }

        return DB::transaction(function () use ($user, $calendarId, $event, $responsibilityId, $start, $end) {
            $session = CalendarSession::withoutEvents(fn () => Task::withoutEvents(function () use ($user, $event, $responsibilityId, $start, $end) {
                $task = new Task(['title' => $this->title($event), 'priority' => 'normal', 'estimate_minutes' => max(1, $this->minutes($event))]);
                $task->user()->associate($user);
                $task->responsibility_id = $responsibilityId;
                $task->save();

                $session = new CalendarSession(['starts_at' => $start, 'ends_at' => $end]);
                $session->user()->associate($user);
                $session->task()->associate($task);
                $session->save();

                return $session;
            }));

            $this->remember($user, $calendarId, $event['id'], 'session', $session->id);

            return $session;
        });
    }

    /**
     * A routine that repeats like the Google series. $series is the series' first event, which carries the
     * repeat rule. Weekly and daily repeats are supported; others are refused with a reason.
     *
     * @param  array<string, mixed>  $series
     *
     * @throws ImportNotPossible
     */
    public function asRoutine(User $user, string $calendarId, array $series, ?int $responsibilityId, string $fallbackTimezone): Routine
    {
        if (empty($series['recurrence'])) {
            throw new ImportNotPossible('This event does not repeat, so it cannot become a routine.');
        }

        if (! isset($series['start']['dateTime'])) {
            throw new ImportNotPossible('An all-day repeating event cannot become a routine yet. Add it as a task instead.');
        }

        $timezone = $this->timezone($series['start']['timeZone'] ?? null, $fallbackTimezone);
        $start = CarbonImmutable::parse($series['start']['dateTime'])->setTimezone($timezone);
        $minutes = $this->minutes($series);

        if ($minutes > self::MAX_MINUTES) {
            throw new ImportNotPossible('This event is longer than 24 hours, which is too long for a routine.');
        }

        try {
            $rule = RecurrenceParser::parse($series['recurrence'], $start);
        } catch (UnsupportedRecurrence $exception) {
            throw new ImportNotPossible($exception->getMessage());
        }

        if ($rule['until'] !== null && $rule['until'] < $start->toDateString()) {
            throw new ImportNotPossible('This repeating event has already ended.');
        }

        return DB::transaction(function () use ($user, $calendarId, $series, $responsibilityId, $timezone, $start, $minutes, $rule) {
            $routine = Routine::withoutEvents(function () use ($user, $series, $responsibilityId, $timezone, $start, $minutes, $rule) {
                $routine = new Routine([
                    'title' => $this->title($series),
                    'days' => $rule['days'],
                    'start_time' => $start->format('H:i:00'),
                    'duration_minutes' => max(5, $minutes),
                    'timezone' => $timezone,
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $rule['until'],
                ]);
                $routine->user()->associate($user);
                $routine->responsibility_id = $responsibilityId;
                $routine->save();

                return $routine;
            });

            // The whole series is remembered, so every one of its events leaves the overlay.
            $this->remember($user, $calendarId, $series['id'], 'routine', $routine->id);

            return $routine;
        });
    }

    private function remember(User $user, string $calendarId, string $eventId, string $kind, int $itemId): void
    {
        $user->googleEventImports()->create([
            'google_calendar_id' => $calendarId,
            'google_event_id' => $eventId,
            'kind' => $kind,
            'item_id' => $itemId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function title(array $event): string
    {
        $title = trim((string) ($event['summary'] ?? ''));

        return mb_substr($title === '' ? '(No title)' : $title, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $moment
     */
    private function instant(array $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment['dateTime'])->utc();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function minutes(array $event): int
    {
        return (int) $this->instant($event['start'])->diffInMinutes($this->instant($event['end']));
    }

    private function timezone(?string $timezone, string $fallback): string
    {
        return $timezone !== null && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : $fallback;
    }
}
