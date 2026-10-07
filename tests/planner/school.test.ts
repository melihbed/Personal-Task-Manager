import { describe, expect, it } from 'vitest';
import { courseColor, courseTitle, groupAssignments, statusOf, type SchoolAssignment } from '../../resources/js/lib/school';

const make = (id: number, due_at: string | null, overrides: Partial<SchoolAssignment> = {}): SchoolAssignment => ({
    id, name: `Work ${id}`, kind: 'assignment', course_id: 1, course_name: 'Data Structures', due_at, points_possible: 10,
    score: null, url: null, submitted: false, missing: false, late: false, done: false, task_id: null, ...overrides,
});

// Wednesday 2026-10-07, noon in New York.
const now = new Date('2026-10-07T16:00:00Z');
const zone = 'America/New_York';

describe('groupAssignments', () => {
    it('sorts open work into overdue, today, next 7 days, later and undated', () => {
        const { open } = groupAssignments([
            make(1, '2026-10-30T12:00:00Z'),
            make(2, null),
            make(3, '2026-10-07T23:00:00Z'),
            make(4, '2026-10-09T12:00:00Z'),
            make(5, '2026-10-06T12:00:00Z'),
        ], now, zone);

        expect(open.map(group => [group.key, group.items.map(item => item.id)])).toEqual([
            ['overdue', [5]], ['today', [3]], ['week', [4]], ['later', [1]], ['undated', [2]],
        ]);
    });

    it('treats work due earlier today as overdue, and later today as today', () => {
        const { open } = groupAssignments([make(1, '2026-10-07T15:00:00Z'), make(2, '2026-10-07T17:00:00Z')], now, zone);

        expect(open.map(group => group.key)).toEqual(['overdue', 'today']);
    });

    it('uses the timezone to decide which day work falls on', () => {
        // 03:00 UTC on the 8th is still the evening of the 7th in New York.
        expect(groupAssignments([make(1, '2026-10-08T03:00:00Z')], now, zone).open[0].key).toBe('today');
        expect(groupAssignments([make(1, '2026-10-08T03:00:00Z')], now, 'Europe/Istanbul').open[0].key).toBe('week');
    });

    it('keeps the soonest work first in a group', () => {
        const { open } = groupAssignments([make(1, '2026-10-09T18:00:00Z'), make(2, '2026-10-08T12:00:00Z')], now, zone);

        expect(open[0].items.map(item => item.id)).toEqual([2, 1]);
    });

    it('returns finished work separately, newest first', () => {
        const { open, done } = groupAssignments([
            make(1, '2026-10-02T12:00:00Z', { submitted: true, done: true }),
            make(2, '2026-10-05T12:00:00Z', { submitted: true, done: true }),
        ], now, zone);

        expect(open).toEqual([]);
        expect(done.map(item => item.id)).toEqual([2, 1]);
    });

    it('can be limited to one course', () => {
        const { open } = groupAssignments([make(1, null, { course_id: 1 }), make(2, null, { course_id: 2 })], now, zone, 2);

        expect(open[0].items.map(item => item.id)).toEqual([2]);
    });
});

describe('statusOf', () => {
    it('names only what is worth knowing', () => {
        expect(statusOf(make(1, null, { submitted: true, done: true }))).toEqual({ label: 'Submitted', variant: 'ok' });
        expect(statusOf(make(1, null, { submitted: true, done: true, late: true }))).toEqual({ label: 'Submitted late', variant: 'warn' });
        expect(statusOf(make(1, null, { done: true }))).toEqual({ label: 'Done', variant: 'ok' });
        expect(statusOf(make(1, '2026-10-01T00:00:00Z', { missing: true }))).toEqual({ label: 'Missing', variant: 'warn' });
        expect(statusOf(make(1, '2026-10-01T00:00:00Z'))).toBeNull();
    });
});

describe('courseTitle', () => {
    it('drops the term and section code', () => {
        expect(courseTitle('FA26-CS474001 Intro to GenAI')).toBe('Intro to GenAI');
        expect(courseTitle('SP25-CS288002 Intensive Programming in Linux')).toBe('Intensive Programming in Linux');
    });

    it('keeps names that would be left empty or have no code', () => {
        expect(courseTitle('FA26-CS/IT491-Eljabiri-MC')).toBe('FA26-CS/IT491-Eljabiri-MC');
        expect(courseTitle('Wellstart')).toBe('Wellstart');
    });
});

describe('courseColor', () => {
    it('gives each course its own colour by position, and the same colour every time', () => {
        const courses = [{ id: 10 }, { id: 20 }, { id: 30 }];

        expect(courseColor(courses, 10)).not.toBe(courseColor(courses, 20));
        expect(courseColor(courses, 20)).toBe(courseColor(courses, 20));
    });
});
