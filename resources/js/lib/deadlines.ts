import { addDays, dateLabel, zonedParts } from './planner';

export type DueInfo = { due_at: string | null; due_has_time: boolean; completed_at: string | null };
export type DueState = 'overdue' | 'today' | 'upcoming' | 'done';

/**
 * A date-only deadline is stored as noon UTC on its date, so its calendar day is the
 * UTC date and never shifts with the viewer's timezone. A timed deadline is an instant.
 */
export function dueDay(task: DueInfo, timezone: string): string | null {
    if (!task.due_at) return null;

    return task.due_has_time ? zonedParts(new Date(task.due_at), timezone).date : task.due_at.slice(0, 10);
}

export function dueMinutes(task: DueInfo, timezone: string): number {
    if (!task.due_at || !task.due_has_time) return 0;

    const [hours, minutes] = zonedParts(new Date(task.due_at), timezone).time.split(':').map(Number);

    return hours * 60 + minutes;
}

export function dueState(task: DueInfo, timezone: string): DueState | null {
    const day = dueDay(task, timezone);
    if (!day || !task.due_at) return null;
    if (task.completed_at) return 'done';

    const today = zonedParts(new Date(), timezone).date;
    const overdue = task.due_has_time ? Date.parse(task.due_at) < Date.now() : day < today;

    if (overdue) return 'overdue';

    return day === today ? 'today' : 'upcoming';
}

export function formatDue(iso: string, hasTime: boolean, timezone: string): string {
    const today = zonedParts(new Date(), timezone).date;
    const day = hasTime ? zonedParts(new Date(iso), timezone).date : iso.slice(0, 10);
    const dayText = day === today
        ? 'Today'
        : day === addDays(today, 1)
            ? 'Tomorrow'
            : day === addDays(today, -1)
                ? 'Yesterday'
                : dateLabel(day, { month: 'short', day: 'numeric' });

    if (!hasTime) return dayText;

    const time = new Intl.DateTimeFormat(undefined, { timeZone: timezone, hour: 'numeric', minute: '2-digit' }).format(new Date(iso));

    return `${dayText} ${time}`;
}

/** Sort key: earliest deadline first; tasks without a deadline sort last. */
export function compareDue(a: DueInfo, b: DueInfo, timezone: string): number {
    const key = (task: DueInfo) => {
        const day = dueDay(task, timezone);
        if (!day) return null;

        return `${day}T${task.due_has_time ? String(dueMinutes(task, timezone)).padStart(4, '0') : '9999'}`;
    };
    const first = key(a);
    const second = key(b);

    if (first === second) return 0;
    if (first === null) return 1;
    if (second === null) return -1;

    return first < second ? -1 : 1;
}
