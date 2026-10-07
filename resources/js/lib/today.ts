import { compareDue, dueDay, dueState } from './deadlines';
import { addDays, zonedParts, type GoogleEvent, type PlannerSession, type PlannerTask, type RoutineOccurrence } from './planner';

const HOURS_48 = 48 * 60 * 60 * 1000;

export type Attention = { overdue: PlannerTask[]; soon: PlannerTask[] };

/**
 * The open tasks that need attention: overdue, and due in the next 48 hours. A date-only deadline counts as
 * "soon" when it falls today or tomorrow. Each list keeps the earliest deadline first.
 */
export function buildAttention(tasks: PlannerTask[], now: Date, timezone: string): Attention {
    const today = zonedParts(now, timezone).date;
    const open = tasks.filter(task => task.due_at && !task.completed_at);
    const isSoon = (task: PlannerTask) => task.due_has_time
        ? Date.parse(task.due_at!) <= now.getTime() + HOURS_48
        : (dueDay(task, timezone) ?? '9999') <= addDays(today, 1);

    return {
        overdue: open.filter(task => dueState(task, timezone) === 'overdue').sort((a, b) => compareDue(a, b, timezone)),
        soon: open.filter(task => dueState(task, timezone) !== 'overdue' && isSoon(task)).sort((a, b) => compareDue(a, b, timezone)),
    };
}

export type AgendaSource =
    | { kind: 'event'; event: GoogleEvent }
    | { kind: 'session'; session: PlannerSession }
    | { kind: 'routine'; occurrence: RoutineOccurrence }
    | { kind: 'deadline'; task: PlannerTask };

export type AgendaItem = {
    key: string;
    title: string;
    /** A short second line: the calendar, responsibility or "Deadline". */
    detail: string;
    color: string | null;
    startsAt: string | null;
    endsAt: string | null;
    allDay: boolean;
    /** past: already over. now: happening. later: still to come. all-day items are always "later". */
    when: 'past' | 'now' | 'later';
    source: AgendaSource;
};

/**
 * What is on today, in order: all-day items first, then by start time. It merges Google events, planned work
 * sessions, routine occurrences and deadlines that fall later today. Finished sessions and routines, and
 * deadlines already past (they show as overdue), are left out.
 */
export function buildAgenda(
    input: { events: GoogleEvent[]; sessions: PlannerSession[]; routines: RoutineOccurrence[]; tasks: PlannerTask[] },
    now: Date,
    timezone: string,
): AgendaItem[] {
    const today = zonedParts(now, timezone).date;
    const dayOf = (iso: string) => zonedParts(new Date(iso), timezone).date;
    const overlapsToday = (startsAt: string, endsAt: string) => dayOf(startsAt) <= today && dayOf(new Date(Date.parse(endsAt) - 1).toISOString()) >= today;
    const whenOf = (startsAt: string, endsAt: string): AgendaItem['when'] => Date.parse(endsAt) <= now.getTime() ? 'past' : Date.parse(startsAt) <= now.getTime() ? 'now' : 'later';
    const items: AgendaItem[] = [];

    for (const event of input.events) {
        if (event.all_day) {
            if (event.start_date && event.end_date && event.start_date <= today && today < event.end_date) {
                items.push({ key: `event:${event.id}`, title: event.title, detail: event.calendar, color: event.color, startsAt: null, endsAt: null, allDay: true, when: 'later', source: { kind: 'event', event } });
            }
        } else if (event.starts_at && event.ends_at && overlapsToday(event.starts_at, event.ends_at)) {
            items.push({ key: `event:${event.id}`, title: event.title, detail: event.calendar, color: event.color, startsAt: event.starts_at, endsAt: event.ends_at, allDay: false, when: whenOf(event.starts_at, event.ends_at), source: { kind: 'event', event } });
        }
    }

    for (const session of input.sessions) {
        if (!session.completed && overlapsToday(session.starts_at, session.ends_at)) {
            items.push({ key: `session:${session.id}`, title: session.title, detail: session.responsibility_name, color: session.color, startsAt: session.starts_at, endsAt: session.ends_at, allDay: false, when: whenOf(session.starts_at, session.ends_at), source: { kind: 'session', session } });
        }
    }

    for (const occurrence of input.routines) {
        if (!occurrence.completed && overlapsToday(occurrence.starts_at, occurrence.ends_at)) {
            items.push({ key: `routine:${occurrence.routine_id}:${occurrence.occurs_on}`, title: occurrence.title, detail: 'Routine', color: occurrence.color, startsAt: occurrence.starts_at, endsAt: occurrence.ends_at, allDay: false, when: whenOf(occurrence.starts_at, occurrence.ends_at), source: { kind: 'routine', occurrence } });
        }
    }

    for (const task of input.tasks) {
        if (task.completed_at || !task.due_at || !task.due_has_time) continue;
        if (dayOf(task.due_at) === today && Date.parse(task.due_at) > now.getTime()) {
            items.push({ key: `deadline:${task.id}`, title: task.title, detail: 'Deadline', color: null, startsAt: task.due_at, endsAt: task.due_at, allDay: false, when: 'later', source: { kind: 'deadline', task } });
        }
    }

    return items.sort((a, b) => {
        if (a.allDay !== b.allDay) return a.allDay ? -1 : 1;

        return (a.startsAt ?? '').localeCompare(b.startsAt ?? '') || a.title.localeCompare(b.title);
    });
}
