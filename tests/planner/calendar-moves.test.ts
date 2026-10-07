import { describe, expect, it } from 'vitest';
import { clock24, eventFormValues, eventUpdatePayload, movedDeadline, movedEvent, taskUpdatePayload } from '../../resources/js/lib/calendar-moves';
import type { PlannerEvent } from '../../resources/js/lib/planner';

const zone = 'America/New_York';

describe('clock24', () => {
    it('writes minutes as a 24-hour clock', () => {
        expect(clock24(0)).toBe('00:00');
        expect(clock24(545)).toBe('09:05');
        expect(clock24(1425)).toBe('23:45');
    });
});

describe('movedDeadline', () => {
    it('moves a timed deadline to the dropped day and time', () => {
        // 5:00 PM on Oct 9 in New York is 21:00 UTC.
        expect(movedDeadline({ due_at: '2026-10-07T23:59:00Z', due_has_time: true }, '2026-10-09', 1020, zone)).toEqual({ due_at: '2026-10-09T21:00:00.000Z', due_has_time: true });
    });

    it('moves a date-only deadline to noon UTC on the dropped day, whatever the timezone', () => {
        expect(movedDeadline({ due_at: '2026-10-07T12:00:00Z', due_has_time: false }, '2026-10-10', null, zone)).toEqual({ due_at: '2026-10-10T12:00:00.000Z', due_has_time: false });
        expect(movedDeadline({ due_at: '2026-10-07T12:00:00Z', due_has_time: false }, '2026-10-10', null, 'Pacific/Auckland')).toEqual({ due_at: '2026-10-10T12:00:00.000Z', due_has_time: false });
    });

    it('keeps a date-only deadline date-only and ignores the time it was dropped at', () => {
        expect(movedDeadline({ due_at: '2026-10-07T12:00:00Z', due_has_time: false }, '2026-10-08', 600, zone)?.due_has_time).toBe(false);
    });

    it('does nothing when the deadline lands where it already is', () => {
        expect(movedDeadline({ due_at: '2026-10-09T21:00:00Z', due_has_time: true }, '2026-10-09', 1020, zone)).toBeNull();
        expect(movedDeadline({ due_at: '2026-10-09T12:00:00Z', due_has_time: false }, '2026-10-09', null, zone)).toBeNull();
    });

    it('refuses a time that does not exist because the clocks jump forward', () => {
        // 2:30 AM on 2027-03-14 does not exist in New York.
        expect(movedDeadline({ due_at: '2027-03-10T12:00:00Z', due_has_time: true }, '2027-03-14', 150, zone)).toBeNull();
    });
});

describe('taskUpdatePayload', () => {
    it('carries everything about the task, with only the deadline changed', () => {
        const task = { title: 'Essay', notes: 'Chapter 3', priority: 'high', estimate_minutes: 90, responsibility_id: 4 };

        expect(taskUpdatePayload(task, { due_at: '2026-10-09T21:00:00.000Z', due_has_time: true })).toEqual({ ...task, due_at: '2026-10-09T21:00:00.000Z', due_has_time: true });
    });
});

const timed = (overrides: Partial<PlannerEvent> = {}): PlannerEvent => ({
    id: 1, title: 'Dentist', location: 'Main St', notes: null, responsibility_id: null, all_day: false,
    starts_at: '2026-10-07T19:00:00Z', ends_at: '2026-10-07T20:30:00Z', start_date: null, end_date: null, ...overrides,
});
const allDay = (start: string, end: string): PlannerEvent => timed({ all_day: true, starts_at: null, ends_at: null, start_date: start, end_date: end });

describe('movedEvent', () => {
    it('moves a timed event to the dropped start and keeps its length', () => {
        expect(movedEvent(timed(), '2026-10-09', 600, zone)).toEqual({ all_day: false, starts_at: '2026-10-09T14:00:00.000Z', ends_at: '2026-10-09T15:30:00.000Z' });
    });

    it('lets a long event run past midnight', () => {
        expect(movedEvent(timed({ ends_at: '2026-10-07T23:00:00Z' }), '2026-10-09', 1320, zone)).toEqual({ all_day: false, starts_at: '2026-10-10T02:00:00.000Z', ends_at: '2026-10-10T06:00:00.000Z' });
    });

    it('does nothing when dropped at its own start', () => {
        expect(movedEvent(timed(), '2026-10-07', 900, zone)).toBeNull();
    });

    it('needs a time to drop a timed event on', () => {
        expect(movedEvent(timed(), '2026-10-09', null, zone)).toBeNull();
    });

    it('refuses a time that does not exist because the clocks jump forward', () => {
        expect(movedEvent(timed(), '2027-03-14', 150, zone)).toBeNull();
    });

    it('moves an all-day event to the dropped day and keeps its number of days (the last day is inclusive)', () => {
        expect(movedEvent(allDay('2026-10-08', '2026-10-09'), '2026-10-12', null, zone)).toEqual({ all_day: true, start_date: '2026-10-12', end_date: '2026-10-13' });
        expect(movedEvent(allDay('2026-10-08', '2026-10-08'), '2026-10-12', null, zone)).toEqual({ all_day: true, start_date: '2026-10-12', end_date: '2026-10-12' });
    });

    it('does nothing for an all-day event dropped on its own first day', () => {
        expect(movedEvent(allDay('2026-10-08', '2026-10-09'), '2026-10-08', null, zone)).toBeNull();
    });
});

describe('eventUpdatePayload', () => {
    it('carries the whole event with only its time changed', () => {
        const times = { all_day: false as const, starts_at: '2026-10-09T14:00:00.000Z', ends_at: '2026-10-09T15:30:00.000Z' };

        expect(eventUpdatePayload(timed({ notes: 'Bring the form' }), times)).toEqual({ title: 'Dentist', location: 'Main St', notes: 'Bring the form', responsibility_id: null, ...times });
    });
});

describe('eventFormValues', () => {
    it('shows a timed event as the clock reads in the timezone', () => {
        expect(eventFormValues(timed(), zone)).toEqual({ startDate: '2026-10-07', startTime: '15:00', endDate: '2026-10-07', endTime: '16:30' });
        expect(eventFormValues(timed(), 'Europe/Istanbul')).toEqual({ startDate: '2026-10-07', startTime: '22:00', endDate: '2026-10-07', endTime: '23:30' });
    });

    it('shows an all-day event by its days', () => {
        expect(eventFormValues(allDay('2026-10-08', '2026-10-09'), zone)).toEqual({ startDate: '2026-10-08', startTime: '', endDate: '2026-10-09', endTime: '' });
    });
});
