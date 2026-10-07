import { addDays, dateLabel, timeLabel, type GoogleEvent } from './planner';

export type ImportType = 'event' | 'task' | 'session' | 'routine';
/** Whether a choice is for one event of a repeating series or for all of them. */
export type Scope = 'event' | 'series';
export type Availability = { available: boolean; reason: string | null };

const MAX_SESSION_MINUTES = 1440;
const ok: Availability = { available: true, reason: null };

/**
 * Which ways a Google event can be brought into the planner. An event or a task is always possible. A work session needs
 * a time of day and at most 24 hours. A routine needs the event to be part of a repeating series.
 */
export function importAvailability(event: GoogleEvent): Record<ImportType, Availability> {
    const minutes = event.starts_at && event.ends_at ? (Date.parse(event.ends_at) - Date.parse(event.starts_at)) / 60000 : null;

    return {
        event: ok,
        task: ok,
        session: event.all_day
            ? { available: false, reason: 'An all-day event has no time to plan.' }
            : minutes !== null && minutes > MAX_SESSION_MINUTES
                ? { available: false, reason: 'This event is longer than 24 hours.' }
                : ok,
        routine: event.recurring_event_id === null
            ? { available: false, reason: 'This event does not repeat.' }
            : event.all_day
                ? { available: false, reason: 'All-day repeating events cannot become routines yet.' }
                : ok,
    };
}

/** When the event happens: "Wed, Oct 7 · 3:00 PM – 4:30 PM", or the day or days for an all-day event. */
export function describeWhen(event: GoogleEvent, timezone: string): string {
    if (event.all_day && event.start_date && event.end_date) {
        const format = { weekday: 'short', month: 'short', day: 'numeric' } as const;
        const last = addDays(event.end_date, -1); // Google's all-day end date is exclusive
        const days = last > event.start_date ? `${dateLabel(event.start_date, format)} – ${dateLabel(last, format)}` : dateLabel(event.start_date, format);

        return `${days} · All day`;
    }

    if (event.starts_at && event.ends_at) {
        const day = new Intl.DateTimeFormat(undefined, { timeZone: timezone, weekday: 'short', month: 'short', day: 'numeric' }).format(new Date(event.starts_at));

        return `${day} · ${timeLabel(event.starts_at, timezone)} – ${timeLabel(event.ends_at, timezone)}`;
    }

    return '';
}

/** What the app says after an event is added. */
export function addedMessage(title: string, type: ImportType): string {
    const label = { event: 'an event', task: 'a task', session: 'a work session', routine: 'a routine' }[type];

    return `Added “${title}” as ${label}.`;
}
