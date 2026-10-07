import { addDays, dateLabel, localToISO, timeLabel, zonedParts, type GoogleEvent } from './planner';

export type ImportType = 'task' | 'session' | 'routine';
export type Availability = { available: boolean; reason: string | null };

const MAX_SESSION_MINUTES = 1440;
const ok: Availability = { available: true, reason: null };

/**
 * Which ways a Google event can be brought into the planner. A task is always possible. A work session needs
 * a time of day and at most 24 hours. A routine needs the event to be part of a repeating series.
 */
export function importAvailability(event: GoogleEvent): Record<ImportType, Availability> {
    const minutes = event.starts_at && event.ends_at ? (Date.parse(event.ends_at) - Date.parse(event.starts_at)) / 60000 : null;

    return {
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
    const label = { task: 'a task', session: 'a work session', routine: 'a routine' }[type];

    return `Added “${title}” as ${label}.`;
}

export type EditScope = 'event' | 'series';
export type EditValues = { title: string; startDate: string; startTime: string; endDate: string; endTime: string };

/** What the edit form starts with: the event as it is now, in the chosen timezone. An all-day event shows its last day, inclusive. */
export function initialEditValues(event: GoogleEvent, timezone: string): EditValues {
    if (event.all_day && event.start_date && event.end_date) {
        return { title: event.title, startDate: event.start_date, startTime: '', endDate: addDays(event.end_date, -1), endTime: '' };
    }

    const start = zonedParts(new Date(event.starts_at ?? 0), timezone);
    const end = zonedParts(new Date(event.ends_at ?? 0), timezone);

    return { title: event.title, startDate: start.date, startTime: start.time, endDate: end.date, endTime: end.time };
}

/**
 * The request that saves an edit to Google. For one event the dates and times are used as given. For a whole
 * series only the time of day and the length matter, so the end is taken on the start's day. Throws an Error
 * with a message to show when the values cannot be saved.
 */
export function buildEditPayload(event: GoogleEvent, scope: EditScope, values: EditValues, timezone: string): Record<string, string | boolean> {
    const title = values.title.trim();

    if (title === '') throw new Error('Give the event a title.');

    const base = { calendar_id: event.calendar_id, event_id: event.event_id, scope, title, all_day: event.all_day, timezone };

    if (event.all_day) {
        if (values.endDate < values.startDate) throw new Error('The last day cannot be before the first day.');

        return { ...base, start_date: values.startDate, end_date: values.endDate };
    }

    if (!values.startDate || !values.startTime || !values.endTime || (scope === 'event' && !values.endDate)) throw new Error('Fill in the date and both times.');

    const startsAt = localToISO(values.startDate, values.startTime, timezone);
    const endsAt = localToISO(scope === 'series' ? values.startDate : values.endDate, values.endTime, timezone);

    if (Date.parse(endsAt) <= Date.parse(startsAt)) throw new Error('The event must end after it starts.');

    return { ...base, starts_at: startsAt, ends_at: endsAt };
}
