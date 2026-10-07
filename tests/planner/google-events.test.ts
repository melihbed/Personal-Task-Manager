import { describe, expect, test } from 'vitest';
import { addedMessage, buildEditPayload, describeWhen, importAvailability, initialEditValues } from '../../resources/js/lib/google-events';
import type { GoogleEvent } from '../../resources/js/lib/planner';

const base: GoogleEvent = {
    id: 'primary|ev1', calendar_id: 'primary', event_id: 'ev1', recurring_event_id: null, title: 'Dentist', calendar: 'Me', color: null,
    all_day: false, starts_at: '2026-10-07T19:00:00+00:00', ends_at: '2026-10-07T20:30:00+00:00', start_date: null, end_date: null,
    html_link: null, location: null, description: null, guests: 0,
};
const allDay: GoogleEvent = { ...base, all_day: true, starts_at: null, ends_at: null, start_date: '2026-10-08', end_date: '2026-10-09' };

describe('which imports are available', () => {
    test('a one-off timed event can be a task or a session but not a routine', () => {
        const result = importAvailability(base);

        expect(result.task.available).toBe(true);
        expect(result.session.available).toBe(true);
        expect(result.routine).toEqual({ available: false, reason: 'This event does not repeat.' });
    });

    test('a repeating timed event can also be a routine', () => {
        expect(importAvailability({ ...base, recurring_event_id: 'series' }).routine.available).toBe(true);
    });

    test('an all-day event can only be a task, whether or not it repeats', () => {
        expect(importAvailability(allDay).task.available).toBe(true);
        expect(importAvailability(allDay).session).toEqual({ available: false, reason: 'An all-day event has no time to plan.' });
        expect(importAvailability({ ...allDay, recurring_event_id: 'series' }).routine.available).toBe(false);
    });

    test('an event longer than a day cannot be a session', () => {
        const long = { ...base, ends_at: '2026-10-09T20:30:00+00:00' };

        expect(importAvailability(long).session).toEqual({ available: false, reason: 'This event is longer than 24 hours.' });
        expect(importAvailability(long).task.available).toBe(true);
    });
});

describe('describing when an event happens', () => {
    test('a timed event shows its day and times in the chosen timezone', () => {
        const text = describeWhen(base, 'America/New_York');

        expect(text).toContain('Oct 7');
        expect(text).toMatch(/3:00\s?PM/);
        expect(text).toMatch(/4:30\s?PM/);
    });

    test('the timezone decides the hours shown', () => {
        expect(describeWhen(base, 'UTC')).toMatch(/7:00\s?PM/);
    });

    test('an all-day event shows its day, and its range when it spans several (the end date is exclusive)', () => {
        expect(describeWhen(allDay, 'America/New_York')).toBe('Thu, Oct 8 · All day');
        expect(describeWhen({ ...allDay, end_date: '2026-10-11' }, 'America/New_York')).toBe('Thu, Oct 8 – Sat, Oct 10 · All day');
    });
});

describe('messages', () => {
    test('says what the event was added as', () => {
        expect(addedMessage('Dentist', 'task')).toBe('Added “Dentist” as a task.');
        expect(addedMessage('Dentist', 'session')).toBe('Added “Dentist” as a work session.');
        expect(addedMessage('Dentist', 'routine')).toBe('Added “Dentist” as a routine.');
    });
});

describe('editing an event', () => {
    const ny = 'America/New_York';

    test('the form starts with the event as it is, in the chosen timezone', () => {
        expect(initialEditValues(base, ny)).toEqual({ title: 'Dentist', startDate: '2026-10-07', startTime: '15:00', endDate: '2026-10-07', endTime: '16:30' });
    });

    test('an all-day event starts with its last day, because Google counts the end date as exclusive', () => {
        expect(initialEditValues(allDay, ny)).toEqual({ title: 'Dentist', startDate: '2026-10-08', startTime: '', endDate: '2026-10-08', endTime: '' });
        expect(initialEditValues({ ...allDay, end_date: '2026-10-11' }, ny).endDate).toBe('2026-10-10');
    });

    test('a timed event is saved with exact instants', () => {
        const payload = buildEditPayload(base, 'event', { title: '  Dentist (moved) ', startDate: '2026-10-08', startTime: '14:00', endDate: '2026-10-08', endTime: '15:00' }, ny);

        expect(payload).toEqual({
            calendar_id: 'primary', event_id: 'ev1', scope: 'event', title: 'Dentist (moved)', all_day: false, timezone: ny,
            starts_at: '2026-10-08T18:00:00.000Z', ends_at: '2026-10-08T19:00:00.000Z',
        });
    });

    test('an all-day event is saved with its dates', () => {
        const payload = buildEditPayload(allDay, 'event', { title: 'Conference', startDate: '2026-10-08', startTime: '', endDate: '2026-10-09', endTime: '' }, ny);

        expect(payload).toMatchObject({ all_day: true, start_date: '2026-10-08', end_date: '2026-10-09' });
        expect(payload).not.toHaveProperty('starts_at');
    });

    test('a whole series uses the start day for both times, so only the time of day and length count', () => {
        const payload = buildEditPayload({ ...base, recurring_event_id: 'series' }, 'series', { title: 'Standup', startDate: '2026-10-07', startTime: '09:00', endDate: '2030-01-01', endTime: '09:30' }, ny);

        expect(payload).toMatchObject({ scope: 'series', starts_at: '2026-10-07T13:00:00.000Z', ends_at: '2026-10-07T13:30:00.000Z' });
    });

    test('values that cannot be saved say why', () => {
        const ok = { title: 'Dentist', startDate: '2026-10-08', startTime: '14:00', endDate: '2026-10-08', endTime: '15:00' };

        expect(() => buildEditPayload(base, 'event', { ...ok, title: '   ' }, ny)).toThrow('Give the event a title.');
        expect(() => buildEditPayload(base, 'event', { ...ok, endTime: '13:00' }, ny)).toThrow('The event must end after it starts.');
        expect(() => buildEditPayload(base, 'event', { ...ok, startTime: '' }, ny)).toThrow('Fill in the date and both times.');
        expect(() => buildEditPayload(allDay, 'event', { ...ok, startDate: '2026-10-10', endDate: '2026-10-08' }, ny)).toThrow('The last day cannot be before the first day.');
    });

    test('a time that does not exist because of daylight saving is refused', () => {
        const values = { title: 'Dentist', startDate: '2026-03-08', startTime: '02:30', endDate: '2026-03-08', endTime: '03:30' };

        expect(() => buildEditPayload(base, 'event', values, ny)).toThrow('daylight saving');
    });
});
