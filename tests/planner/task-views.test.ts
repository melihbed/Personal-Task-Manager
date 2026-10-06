import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import type { PlannerTask } from '../../resources/js/lib/planner';
import { buildSections, completedTasks, filterTasks } from '../../resources/js/lib/task-views';

const timezone = 'America/New_York';
let nextId = 1;

function task(overrides: Partial<PlannerTask> = {}): PlannerTask {
    return {
        id: nextId++, responsibility_id: null, title: `Task ${nextId}`, priority: 'normal', estimate_minutes: null,
        due_at: null, due_has_time: true, completed_at: null, calendar_sessions_count: 0, ...overrides,
    };
}

beforeEach(() => {
    nextId = 1;
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-06T14:00:00Z')); // Tuesday 10:00 AM in New York
});

afterEach(() => vi.useRealTimers());

describe('planning view', () => {
    test('splits open tasks into needs a time and planned, and leaves out empty sections', () => {
        const sections = buildSections([task({ title: 'A' }), task({ title: 'B', calendar_sessions_count: 2 })], 'planning', timezone);

        expect(sections.map(section => [section.key, section.tasks.map(item => item.title)])).toEqual([['needs', ['A']], ['planned', ['B']]]);
        expect(buildSections([task({ calendar_sessions_count: 1 })], 'planning', timezone).map(section => section.key)).toEqual(['planned']);
    });

    test('orders by deadline with undated tasks last', () => {
        const sections = buildSections([
            task({ title: 'No date' }),
            task({ title: 'Later', due_at: '2026-10-09T12:00:00Z', due_has_time: false }),
            task({ title: 'Sooner', due_at: '2026-10-07T15:00:00Z' }),
        ], 'planning', timezone);

        expect(sections[0].tasks.map(item => item.title)).toEqual(['Sooner', 'Later', 'No date']);
    });

    test('ignores completed tasks', () => {
        expect(buildSections([task({ completed_at: '2026-10-05T12:00:00Z' })], 'planning', timezone)).toEqual([]);
    });
});

describe('due view', () => {
    test('buckets tasks by when they are due', () => {
        const sections = buildSections([
            task({ title: 'Overdue timed', due_at: '2026-10-06T13:00:00Z' }), // 9 AM today, already past
            task({ title: 'Overdue day', due_at: '2026-10-05T12:00:00Z', due_has_time: false }),
            task({ title: 'Today timed', due_at: '2026-10-06T22:00:00Z' }),
            task({ title: 'Today day', due_at: '2026-10-06T12:00:00Z', due_has_time: false }),
            task({ title: 'Tomorrow', due_at: '2026-10-07T12:00:00Z', due_has_time: false }),
            task({ title: 'This week', due_at: '2026-10-10T12:00:00Z', due_has_time: false }),
            task({ title: 'Later', due_at: '2026-11-01T12:00:00Z', due_has_time: false }),
            task({ title: 'None' }),
        ], 'due', timezone);

        expect(sections.map(section => [section.key, section.tasks.map(item => item.title)])).toEqual([
            ['overdue', ['Overdue day', 'Overdue timed']],
            ['today', ['Today timed', 'Today day']],
            ['tomorrow', ['Tomorrow']],
            ['week', ['This week']],
            ['later', ['Later']],
            ['none', ['None']],
        ]);
    });

    test('marks the overdue and today sections with a tone', () => {
        const sections = buildSections([
            task({ due_at: '2026-10-05T12:00:00Z', due_has_time: false }),
            task({ due_at: '2026-10-06T22:00:00Z' }),
        ], 'due', timezone);

        expect(sections.map(section => section.tone)).toEqual(['overdue', 'today']);
    });

    test('a date-only deadline is not overdue until its day has passed', () => {
        const sections = buildSections([task({ title: 'Today', due_at: '2026-10-06T12:00:00Z', due_has_time: false })], 'due', timezone);

        expect(sections.map(section => section.key)).toEqual(['today']);
    });
});

describe('filtering and done', () => {
    test('filters by responsibility or the inbox', () => {
        const tasks = [task({ responsibility_id: null }), task({ responsibility_id: 3 }), task({ responsibility_id: 4 })];

        expect(filterTasks(tasks, 'all')).toHaveLength(3);
        expect(filterTasks(tasks, 'inbox').map(item => item.responsibility_id)).toEqual([null]);
        expect(filterTasks(tasks, 3).map(item => item.responsibility_id)).toEqual([3]);
    });

    test('lists completed tasks most recent first', () => {
        const done = completedTasks([
            task({ title: 'Old', completed_at: '2026-10-01T12:00:00Z' }),
            task({ title: 'Open' }),
            task({ title: 'New', completed_at: '2026-10-05T12:00:00Z' }),
        ]);

        expect(done.map(item => item.title)).toEqual(['New', 'Old']);
    });
});
