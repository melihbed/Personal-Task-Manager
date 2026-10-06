<?php

namespace App\Services\GoogleCalendar;

use App\Models\CalendarSession;
use App\Models\Routine;
use App\Models\RoutineOccurrence;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Builds Google Calendar event payloads from planner items. Every pushed event carries a private
 * extended property so it can be recognized later, and left out when Google events are imported.
 */
class GoogleEventMapper
{
    /** The private extended property that marks an event as created by this app. */
    public const MARKER = 'planner';

    private const WEEKDAYS = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

    private const DEADLINE_MINUTES = 15;

    /**
     * @return array<string, mixed>
     */
    public function session(CalendarSession $session, Task $task): array
    {
        return [
            'summary' => $task->title,
            'description' => $this->description($task->responsibility?->name),
            'start' => $this->instant($session->starts_at),
            'end' => $this->instant($session->ends_at),
            'extendedProperties' => $this->marker('session', $session->id),
        ];
    }

    /**
     * A timed deadline is a short event at the due time; a date-only deadline is an all-day event. Neither
     * blocks the user's time in Google.
     *
     * @return array<string, mixed>
     */
    public function deadline(Task $task): array
    {
        $due = CarbonImmutable::instance($task->due_at)->utc();

        return [
            'summary' => 'Due: '.$task->title,
            'description' => $this->description($task->responsibility?->name),
            'start' => $task->due_has_time ? $this->instant($due) : ['date' => $due->toDateString()],
            'end' => $task->due_has_time ? $this->instant($due->addMinutes(self::DEADLINE_MINUTES)) : ['date' => $due->addDay()->toDateString()],
            'transparency' => 'transparent',
            'extendedProperties' => $this->marker('deadline', $task->id),
        ];
    }

    /**
     * One recurring event for the whole routine. Skipped days become EXDATEs. The weekday, local start
     * time and timezone are the routine's own, so daylight saving changes are Google's to apply.
     *
     * @param  list<string>  $skippedDates  Y-m-d dates in the routine's timezone
     * @return array<string, mixed>
     */
    public function routine(Routine $routine, array $skippedDates = []): array
    {
        $first = $this->firstDate($routine);
        $start = CarbonImmutable::parse("{$first} {$routine->start_time}", $routine->timezone);
        $recurrence = [$this->recurrenceRule($routine)];

        $exdates = collect($skippedDates)
            ->filter(fn (string $date) => $date >= $first && $routine->occursOn($date))
            ->sort()
            ->map(fn (string $date) => CarbonImmutable::parse("{$date} {$routine->start_time}", $routine->timezone)->format('Ymd\THis'))
            ->values();

        if ($exdates->isNotEmpty()) {
            $recurrence[] = "EXDATE;TZID={$routine->timezone}:".$exdates->implode(',');
        }

        return [
            'summary' => $routine->title,
            'description' => $this->description($routine->responsibility?->name),
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $routine->timezone],
            'end' => ['dateTime' => $start->addMinutes($routine->duration_minutes)->format('Y-m-d\TH:i:s'), 'timeZone' => $routine->timezone],
            'recurrence' => $recurrence,
            'extendedProperties' => $this->marker('routine', $routine->id),
        ];
    }

    public function recurrenceRule(Routine $routine): string
    {
        $days = collect($routine->days)->sort()->map(fn (int $day) => self::WEEKDAYS[$day])->implode(',');
        $rule = "RRULE:FREQ=WEEKLY;BYDAY={$days}";

        if ($routine->ends_on !== null) {
            // UNTIL must be in UTC when the event start is a date-time; use the last moment of the last day.
            $rule .= ';UNTIL='.CarbonImmutable::parse($routine->ends_on->toDateString().' 23:59:59', $routine->timezone)->utc()->format('Ymd\THis\Z');
        }

        return $rule;
    }

    /**
     * The change to one moved day of a routine: the instance's new start and end.
     *
     * @return array<string, mixed>
     */
    public function movedOccurrence(RoutineOccurrence $occurrence): array
    {
        return ['start' => $this->instant($occurrence->starts_at), 'end' => $this->instant($occurrence->ends_at)];
    }

    /**
     * An occurrence's usual start and end, used to put a moved day back.
     *
     * @return array{start: array<string, string>, end: array<string, string>}
     */
    public function usualTimes(Routine $routine, string $date): array
    {
        $start = CarbonImmutable::parse("{$date} {$routine->start_time}", $routine->timezone)->utc();

        return ['start' => $this->instant($start), 'end' => $this->instant($start->addMinutes($routine->duration_minutes))];
    }

    /** Google's id for one instance of a recurring event: the master id plus the instance's original UTC start. */
    public function instanceId(string $masterId, Routine $routine, string $date): string
    {
        return $masterId.'_'.CarbonImmutable::parse("{$date} {$routine->start_time}", $routine->timezone)->utc()->format('Ymd\THis\Z');
    }

    private function firstDate(Routine $routine): string
    {
        $date = CarbonImmutable::parse($routine->starts_on->toDateString(), $routine->timezone);

        for ($offset = 0; $offset < 7; $offset++) {
            if (in_array($date->addDays($offset)->isoWeekday(), $routine->days, true)) {
                return $date->addDays($offset)->toDateString();
            }
        }

        return $date->toDateString();
    }

    /**
     * @return array{dateTime: string, timeZone: string}
     */
    private function instant(\DateTimeInterface $moment): array
    {
        return ['dateTime' => CarbonImmutable::instance($moment)->utc()->format('Y-m-d\TH:i:s\Z'), 'timeZone' => 'UTC'];
    }

    /**
     * @return array{private: array<string, string>}
     */
    private function marker(string $kind, int $id): array
    {
        return ['private' => [self::MARKER => $kind, 'plannerId' => (string) $id]];
    }

    private function description(?string $responsibility): string
    {
        return ($responsibility ? "Responsibility: {$responsibility}\n" : '').'Planned in Personal Manager.';
    }
}
