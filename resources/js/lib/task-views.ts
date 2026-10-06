import { compareDue, dueDay, dueState } from './deadlines';
import { addDays, zonedParts, type PlannerTask } from './planner';

/** How the open tasks are grouped: by whether they have calendar time yet, or by when they are due. */
export type TaskView = 'planning' | 'due';
export type ResponsibilityFilter = 'all' | 'inbox' | number;
export type SectionTone = 'overdue' | 'today' | 'default';
export type TaskSection = { key: string; title: string; tone: SectionTone; tasks: PlannerTask[] };

export const taskViews: { value: TaskView; label: string }[] = [
    { value: 'planning', label: 'Needs a time' },
    { value: 'due', label: 'By due date' },
];

export function filterTasks(tasks: PlannerTask[], filter: ResponsibilityFilter): PlannerTask[] {
    if (filter === 'all') return tasks;
    if (filter === 'inbox') return tasks.filter(task => task.responsibility_id === null);

    return tasks.filter(task => task.responsibility_id === filter);
}

/** Open tasks grouped for the chosen view. Empty sections are omitted; tasks keep their deadline order. */
export function buildSections(tasks: PlannerTask[], view: TaskView, timezone: string): TaskSection[] {
    const open = tasks.filter(task => !task.completed_at).sort((a, b) => compareDue(a, b, timezone));

    const sections: TaskSection[] = view === 'planning'
        ? [
            { key: 'needs', title: 'Needs a time', tone: 'default', tasks: open.filter(task => task.calendar_sessions_count === 0) },
            { key: 'planned', title: 'Planned', tone: 'default', tasks: open.filter(task => task.calendar_sessions_count > 0) },
        ]
        : dueSections(open, timezone);

    return sections.filter(section => section.tasks.length > 0);
}

function dueSections(open: PlannerTask[], timezone: string): TaskSection[] {
    const today = zonedParts(new Date(), timezone).date;
    const tomorrow = addDays(today, 1);
    const nextWeek = addDays(today, 7);
    const sections: Record<string, TaskSection> = {
        overdue: { key: 'overdue', title: 'Overdue', tone: 'overdue', tasks: [] },
        today: { key: 'today', title: 'Today', tone: 'today', tasks: [] },
        tomorrow: { key: 'tomorrow', title: 'Tomorrow', tone: 'default', tasks: [] },
        week: { key: 'week', title: 'Next 7 days', tone: 'default', tasks: [] },
        later: { key: 'later', title: 'Later', tone: 'default', tasks: [] },
        none: { key: 'none', title: 'No due date', tone: 'default', tasks: [] },
    };

    for (const task of open) {
        const day = dueDay(task, timezone);
        const key = !day
            ? 'none'
            : dueState(task, timezone) === 'overdue'
                ? 'overdue'
                : day === today ? 'today' : day === tomorrow ? 'tomorrow' : day <= nextWeek ? 'week' : 'later';

        sections[key].tasks.push(task);
    }

    return Object.values(sections);
}

/** Completed tasks, most recently finished first. */
export function completedTasks(tasks: PlannerTask[]): PlannerTask[] {
    return tasks
        .filter(task => task.completed_at)
        .sort((a, b) => Date.parse(b.completed_at ?? '') - Date.parse(a.completed_at ?? ''));
}
