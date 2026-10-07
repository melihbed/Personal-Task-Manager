import { describe, expect, test } from 'vitest';
import { addedMessage, describeWhen, importAvailability } from '../../resources/js/lib/google-events';
import type { GoogleEvent } from '../../resources/js/lib/planner';

const base: GoogleEvent = {
    id: 'primary|ev1', calendar_id: 'primary', event_id: 'ev1', recurring_event_id: null, title: 'Dentist', calendar: 'Me', color: null,
    all_day: false, starts_at: '2026-10-07T19:00:00+00:00', ends_at: '2026-10-07T20:30:00+00:00', start_date: null, end_date: null,
    html_link: null, location: null, description: null, guests: 0,
};
const allDay: GoogleEvent = { ...base, all_day: true, starts_at: null, ends_at: null, start_date: '2026-10-08', end_date: '2026-10-09' };

describe('which imports are available', () => {
    test('a one-off timed event can be an event, a task or a session but not a routine', () => {
        const result = importAvailability(base);

        expect(result.event.available).toBe(true);
        expect(result.task.available).toBe(true);
        expect(result.session.available).toBe(true);
        expect(result.routine).toEqual({ available: false, reason: 'This event does not repeat.' });
    });

    test('a repeating timed event can also be a routine', () => {
        expect(importAvailability({ ...base, recurring_event_id: 'series' }).routine.available).toBe(true);
    });

    test('an all-day event can be an event or a task, but not a session or routine, whether or not it repeats', () => {
        expect(importAvailability(allDay).event.available).toBe(true);
        expect(importAvailability(allDay).task.available).toBe(true);
        expect(importAvailability(allDay).session).toEqual({ available: false, reason: 'An all-day event has no time to plan.' });
        expect(importAvailability({ ...allDay, recurring_event_id: 'series' }).routine.available).toBe(false);
    });

    test('an event longer than a day cannot be a session', () => {
        const long = { ...base, ends_at: '2026-10-09T20:30:00+00:00' };

        expect(importAvailability(long).session).toEqual({ available: false, reason: 'This event is longer than 24 hours.' });
        expect(importAvailability(long).task.available).toBe(true);
        expect(importAvailability(long).event.available).toBe(true);
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
        expect(addedMessage('Dentist', 'event')).toBe('Added “Dentist” as an event.');
        expect(addedMessage('Dentist', 'task')).toBe('Added “Dentist” as a task.');
        expect(addedMessage('Dentist', 'session')).toBe('Added “Dentist” as a work session.');
        expect(addedMessage('Dentist', 'routine')).toBe('Added “Dentist” as a routine.');
    });
});
