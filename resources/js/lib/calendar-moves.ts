import { addDays, localToISO, zonedParts, type PlannerEvent, type PlannerTask } from './planner';

/** Minutes since midnight as a 24-hour "HH:MM". */
export const clock24 = (minutes: number) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

/**
 * A deadline moved to a new day, and for a timed deadline a new time (minutes since midnight). A date-only deadline is stored
 * as noon UTC on its date. Returns null when nothing would change, or when the time cannot exist (a daylight saving gap).
 */
export function movedDeadline(task: Pick<PlannerTask, 'due_at' | 'due_has_time'>, date: string, minutes: number | null, timezone: string): { due_at: string; due_has_time: boolean } | null {
    let dueAt: string;

    try {
        dueAt = task.due_has_time && minutes !== null ? localToISO(date, clock24(minutes), timezone) : `${date}T12:00:00Z`;
    } catch {
        return null;
    }

    const hasTime = task.due_has_time;

    if (task.due_at !== null && Date.parse(task.due_at) === Date.parse(dueAt)) return null;

    return { due_at: new Date(dueAt).toISOString(), due_has_time: hasTime };
}

/** Everything the task update endpoint needs, so a move changes only the deadline. */
export function taskUpdatePayload(task: Pick<PlannerTask, 'title' | 'notes' | 'priority' | 'estimate_minutes' | 'responsibility_id'>, deadline: { due_at: string | null; due_has_time: boolean }) {
    return {
        title: task.title,
        notes: task.notes,
        priority: task.priority,
        estimate_minutes: task.estimate_minutes,
        responsibility_id: task.responsibility_id,
        due_at: deadline.due_at,
        due_has_time: deadline.due_has_time,
    };
}

export type EventTimes = { all_day: false; starts_at: string; ends_at: string } | { all_day: true; start_date: string; end_date: string };

const daysBetween = (from: string, to: string) => Math.round((Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) / 86_400_000);

/**
 * The times of an event dropped on a new day (and, for a timed event, a new start). The length is kept; an all-day event keeps
 * its number of days. Returns null when nothing would change, or when the time cannot exist.
 */
export function movedEvent(event: PlannerEvent, date: string, minutes: number | null, timezone: string): EventTimes | null {
    if (event.all_day && event.start_date && event.end_date) {
        if (event.start_date === date) return null;

        return { all_day: true, start_date: date, end_date: addDays(date, daysBetween(event.start_date, event.end_date)) };
    }

    if (!event.starts_at || !event.ends_at || minutes === null) return null;

    let startsAt: string;

    try {
        startsAt = localToISO(date, clock24(minutes), timezone);
    } catch {
        return null;
    }

    if (Date.parse(startsAt) === Date.parse(event.starts_at)) return null;

    return { all_day: false, starts_at: new Date(startsAt).toISOString(), ends_at: new Date(Date.parse(startsAt) + (Date.parse(event.ends_at) - Date.parse(event.starts_at))).toISOString() };
}

/** Everything the event update endpoint needs, so a move changes only the time. */
export function eventUpdatePayload(event: Pick<PlannerEvent, 'title' | 'location' | 'notes' | 'responsibility_id'>, times: EventTimes) {
    return { title: event.title, location: event.location, notes: event.notes, responsibility_id: event.responsibility_id, ...times };
}

/** A timed event's start and end as the clock shows them in a timezone, for filling a form. */
export function eventFormValues(event: PlannerEvent, timezone: string): { startDate: string; startTime: string; endDate: string; endTime: string } {
    if (event.all_day) return { startDate: event.start_date ?? '', startTime: '', endDate: event.end_date ?? '', endTime: '' };

    const start = zonedParts(new Date(event.starts_at ?? 0), timezone);
    const end = zonedParts(new Date(event.ends_at ?? 0), timezone);

    return { startDate: start.date, startTime: start.time, endDate: end.date, endTime: end.time };
}
