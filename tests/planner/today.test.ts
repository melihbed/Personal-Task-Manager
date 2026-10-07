import { describe, expect, it } from 'vitest';
import type { GoogleEvent, PlannerSession, PlannerTask, RoutineOccurrence } from '../../resources/js/lib/planner';
import { buildAgenda, buildAttention, isPlanned } from '../../resources/js/lib/today';

// Wednesday 2026-10-07, 1:40 PM in New York (17:40 UTC).
const now = new Date('2026-10-07T17:40:00Z');
const zone = 'America/New_York';

const task = (id: number, due_at: string | null, overrides: Partial<PlannerTask> = {}): PlannerTask => ({
    id, responsibility_id: null, title: `Task ${id}`, notes: null, priority: 'normal', estimate_minutes: null,
    due_at, due_has_time: true, completed_at: null, calendar_sessions_count: 0, ...overrides,
});

describe('planned tasks', () => {
    it('leave Needs attention once a session is reserved before the deadline', () => {
        const { soon } = buildAttention([
            task(1, '2026-10-07T23:00:00Z', { next_session_at: '2026-10-07T20:00:00Z' }),
            task(2, '2026-10-07T23:00:00Z', { next_session_at: '2026-10-08T01:00:00Z' }),
            task(3, '2026-10-07T23:00:00Z', { next_session_at: null }),
        ], now, zone);

        expect(soon.map(item => item.id)).toEqual([2, 3]);
    });

    it('stay when they are overdue, even with a session planned', () => {
        const { overdue } = buildAttention([task(1, '2026-10-06T12:00:00Z', { next_session_at: '2026-10-07T20:00:00Z' })], now, zone);

        expect(overdue.map(item => item.id)).toEqual([1]);
    });

    it('treat a date-only deadline as met by a session on or before that day', () => {
        const dateOnly = (session: string) => task(1, '2026-10-08T12:00:00Z', { due_has_time: false, next_session_at: session });

        expect(isPlanned(dateOnly('2026-10-08T22:00:00Z'), zone)).toBe(true);
        expect(isPlanned(dateOnly('2026-10-09T15:00:00Z'), zone)).toBe(false);
    });
});

describe('buildAttention', () => {
    it('separates overdue work from work due in the next 48 hours', () => {
        const { overdue, soon } = buildAttention([
            task(1, '2026-10-06T12:00:00Z'),
            task(2, '2026-10-08T12:00:00Z'),
            task(3, '2026-10-09T17:00:00Z'),
            task(4, '2026-10-09T18:00:00Z'),
            task(5, null),
        ], now, zone);

        expect(overdue.map(item => item.id)).toEqual([1]);
        expect(soon.map(item => item.id)).toEqual([2, 3]);
    });

    it('leaves out finished tasks', () => {
        const { overdue, soon } = buildAttention([task(1, '2026-10-06T12:00:00Z', { completed_at: '2026-10-06T13:00:00Z' }), task(2, '2026-10-08T12:00:00Z', { completed_at: '2026-10-07T00:00:00Z' })], now, zone);

        expect(overdue).toEqual([]);
        expect(soon).toEqual([]);
    });

    it('counts a date-only deadline for today or tomorrow as soon, and an earlier date as overdue', () => {
        const dateOnly = (id: number, day: string) => task(id, `${day}T12:00:00Z`, { due_has_time: false });
        const { overdue, soon } = buildAttention([dateOnly(1, '2026-10-06'), dateOnly(2, '2026-10-07'), dateOnly(3, '2026-10-08'), dateOnly(4, '2026-10-09')], now, zone);

        expect(overdue.map(item => item.id)).toEqual([1]);
        expect(soon.map(item => item.id)).toEqual([2, 3]);
    });

    it('lists the earliest deadline first', () => {
        const { soon } = buildAttention([task(1, '2026-10-09T10:00:00Z'), task(2, '2026-10-08T10:00:00Z')], now, zone);

        expect(soon.map(item => item.id)).toEqual([2, 1]);
    });
});

const event = (id: string, overrides: Partial<GoogleEvent> = {}): GoogleEvent => ({
    id, calendar_id: 'c', event_id: id, recurring_event_id: null, title: `Event ${id}`, calendar: 'Me', color: null, all_day: false,
    starts_at: '2026-10-07T19:00:00Z', ends_at: '2026-10-07T20:00:00Z', start_date: null, end_date: null, html_link: null, location: null, description: null, guests: 0, ...overrides,
});
const session = (id: number, starts_at: string, ends_at: string, completed = false): PlannerSession => ({ id, task_id: id, title: `Session ${id}`, responsibility_name: 'School', color: null, completed, starts_at, ends_at });
const occurrence = (id: number, starts_at: string, ends_at: string, completed = false): RoutineOccurrence => ({ routine_id: id, occurs_on: '2026-10-07', title: `Routine ${id}`, responsibility_name: 'Home', color: null, starts_at, ends_at, completed, moved: false });
const agenda = (input: Partial<Parameters<typeof buildAgenda>[0]>, at = now, zoneName = zone) => buildAgenda({ events: [], sessions: [], routines: [], tasks: [], ...input }, at, zoneName);

describe('buildAgenda', () => {
    it('merges events, sessions, routines and later deadlines in time order, all-day first', () => {
        const items = agenda({
            events: [event('late', { starts_at: '2026-10-07T22:00:00Z', ends_at: '2026-10-07T23:00:00Z' }), event('holiday', { all_day: true, starts_at: null, ends_at: null, start_date: '2026-10-07', end_date: '2026-10-08' })],
            sessions: [session(1, '2026-10-07T19:00:00Z', '2026-10-07T20:00:00Z')],
            routines: [occurrence(1, '2026-10-07T18:00:00Z', '2026-10-07T18:30:00Z')],
            tasks: [task(9, '2026-10-08T03:30:00Z'), task(8, '2026-10-07T21:00:00Z')],
        });

        expect(items.map(item => item.key)).toEqual(['event:holiday', 'routine:1:2026-10-07', 'session:1', 'deadline:8', 'event:late', 'deadline:9']);
    });

    it('marks what is over, happening now, and still to come', () => {
        const items = agenda({ sessions: [
            session(1, '2026-10-07T15:00:00Z', '2026-10-07T16:00:00Z'),
            session(2, '2026-10-07T17:00:00Z', '2026-10-07T18:00:00Z'),
            session(3, '2026-10-07T19:00:00Z', '2026-10-07T20:00:00Z'),
        ] });

        expect(items.map(item => item.when)).toEqual(['past', 'now', 'later']);
    });

    it('leaves out other days, finished sessions and routines, and deadlines that are past or done', () => {
        const items = agenda({
            events: [event('tomorrow', { starts_at: '2026-10-08T15:00:00Z', ends_at: '2026-10-08T16:00:00Z' }), event('all-day-tomorrow', { all_day: true, starts_at: null, ends_at: null, start_date: '2026-10-08', end_date: '2026-10-09' })],
            sessions: [session(1, '2026-10-07T19:00:00Z', '2026-10-07T20:00:00Z', true)],
            routines: [occurrence(1, '2026-10-07T19:00:00Z', '2026-10-07T20:00:00Z', true)],
            tasks: [task(1, '2026-10-07T15:00:00Z'), task(2, '2026-10-07T21:00:00Z', { completed_at: '2026-10-07T00:00:00Z' }), task(3, '2026-10-07T12:00:00Z', { due_has_time: false })],
        });

        expect(items).toEqual([]);
    });

    it('uses the timezone to decide what is today', () => {
        // 02:00 UTC on the 8th is the evening of the 7th in New York, but already the 8th in Istanbul.
        const lateEvent = event('x', { starts_at: '2026-10-08T02:00:00Z', ends_at: '2026-10-08T03:00:00Z' });

        expect(agenda({ events: [lateEvent] }).map(item => item.key)).toEqual(['event:x']);
        expect(agenda({ events: [lateEvent] }, now, 'Europe/Istanbul')).toEqual([]);
    });

    it('keeps an event that started yesterday and runs into today', () => {
        const overnight = event('night', { starts_at: '2026-10-07T02:00:00Z', ends_at: '2026-10-07T16:00:00Z' });

        expect(agenda({ events: [overnight] }).map(item => item.when)).toEqual(['past']);
    });
});
