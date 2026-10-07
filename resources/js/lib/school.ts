import { addDays, zonedParts } from './planner';

export type SchoolAssignment = {
    id: number;
    name: string;
    kind: 'assignment' | 'quiz' | 'discussion';
    course_id: number;
    course_name: string;
    due_at: string | null;
    points_possible: number | null;
    score: number | null;
    url: string | null;
    submitted: boolean;
    missing: boolean;
    late: boolean;
    /** Finished: submitted in Canvas, or its task was completed here. */
    done: boolean;
    task_id: number | null;
};

export type SchoolGroupKey = 'overdue' | 'today' | 'week' | 'later' | 'undated';
export type SchoolGroup = { key: SchoolGroupKey; title: string; items: SchoolAssignment[] };

const titles: Record<SchoolGroupKey, string> = {
    overdue: 'Overdue',
    today: 'Today',
    week: 'Next 7 days',
    later: 'Later',
    undated: 'No due date',
};

/**
 * Work still to do grouped by how soon it is due. Overdue means the due time has passed. Empty groups are
 * left out, and each group keeps the soonest work first. Finished work is returned separately.
 */
export function groupAssignments(items: SchoolAssignment[], now: Date, timezone: string, courseId: number | null = null): { open: SchoolGroup[]; done: SchoolAssignment[] } {
    const today = zonedParts(now, timezone).date;
    const weekEnd = addDays(today, 7);
    const chosen = courseId === null ? items : items.filter(item => item.course_id === courseId);
    const byDue = (a: SchoolAssignment, b: SchoolAssignment) => (a.due_at ?? '9999').localeCompare(b.due_at ?? '9999');

    const keyOf = (item: SchoolAssignment): SchoolGroupKey => {
        if (!item.due_at) return 'undated';
        if (Date.parse(item.due_at) < now.getTime()) return 'overdue';

        const day = zonedParts(new Date(item.due_at), timezone).date;

        if (day === today) return 'today';

        return day < weekEnd ? 'week' : 'later';
    };

    const open = chosen.filter(item => !item.done).sort(byDue);
    const groups = (Object.keys(titles) as SchoolGroupKey[])
        .map(key => ({ key, title: titles[key], items: open.filter(item => keyOf(item) === key) }))
        .filter(group => group.items.length > 0);

    return { open: groups, done: chosen.filter(item => item.done).sort((a, b) => byDue(b, a)) };
}

/** The badge for a piece of work, only when there is something worth saying: open work needs no label. */
export function statusOf(item: SchoolAssignment): { label: string; variant: 'ok' | 'warn' } | null {
    if (item.submitted) return item.late ? { label: 'Submitted late', variant: 'warn' } : { label: 'Submitted', variant: 'ok' };
    if (item.done) return { label: 'Done', variant: 'ok' };
    if (item.missing) return { label: 'Missing', variant: 'warn' };

    return null;
}

const palette = ['#e64b27', '#79a7cf', '#2f8f6b', '#b45309', '#7c5cbf', '#c2418c'];

/** Each course keeps one colour everywhere on the page, by its place in the course list. */
export function courseColor(courses: { id: number }[], courseId: number): string {
    return palette[Math.max(0, courses.findIndex(course => course.id === courseId)) % palette.length];
}

/** "FA26-CS474001 Intro to GenAI" reads as "Intro to GenAI". Names without a term code, or that are only a code, stay as they are. */
export function courseTitle(name: string): string {
    const stripped = name.replace(/^(FA|SP|SU|WI)\d{2}-\S+\s+/i, '').trim();

    return stripped === '' ? name : stripped;
}

export const kindLabels: Record<SchoolAssignment['kind'], string> = { assignment: 'Assignment', quiz: 'Quiz', discussion: 'Discussion' };
