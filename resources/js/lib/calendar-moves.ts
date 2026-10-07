import type { EditValues } from './google-events';
import { addDays, localToISO, zonedParts, type GoogleEvent, type PlannerTask } from './planner';

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

const daysBetween = (from: string, to: string) => Math.round((Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) / 86_400_000);

/**
 * The values to save when a Google event is dropped on a new day (and, for a timed event, a new start). The length is kept; an
 * all-day event keeps its number of days. Returns null when nothing would change or the time cannot exist.
 */
export function movedGoogleValues(event: GoogleEvent, date: string, minutes: number | null, timezone: string): EditValues | null {
    if (event.all_day && event.start_date && event.end_date) {
        if (event.start_date === date) return null;

        const lastDay = addDays(event.end_date, -1);

        return { title: event.title, startDate: date, startTime: '', endDate: addDays(date, daysBetween(event.start_date, lastDay)), endTime: '' };
    }

    if (!event.starts_at || !event.ends_at || minutes === null) return null;

    let startsAt: string;

    try {
        startsAt = localToISO(date, clock24(minutes), timezone);
    } catch {
        return null;
    }

    if (Date.parse(startsAt) === Date.parse(event.starts_at)) return null;

    const start = zonedParts(new Date(startsAt), timezone);
    const end = zonedParts(new Date(Date.parse(startsAt) + (Date.parse(event.ends_at) - Date.parse(event.starts_at))), timezone);

    return { title: event.title, startDate: start.date, startTime: start.time, endDate: end.date, endTime: end.time };
}
