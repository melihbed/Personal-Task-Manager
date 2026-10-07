import { describe, expect, test } from 'vitest';
import { calendarScrollTop, minutesSinceMidnight } from '../../resources/js/lib/planner';

describe('placing the current time', () => {
    test('counts the minutes into the day in the chosen timezone', () => {
        const instant = new Date('2026-10-07T19:30:00Z');

        expect(minutesSinceMidnight(instant, 'UTC')).toBe(19 * 60 + 30);
        expect(minutesSinceMidnight(instant, 'America/New_York')).toBe(15 * 60 + 30); // EDT, UTC-4
        expect(minutesSinceMidnight(instant, 'Europe/Istanbul')).toBe(22 * 60 + 30); // UTC+3
    });

    test('seconds move the line smoothly between minutes', () => {
        expect(minutesSinceMidnight(new Date('2026-10-07T12:00:30Z'), 'UTC')).toBeCloseTo(12 * 60 + 0.5);
    });

    test('midnight is zero, and the minute before it is almost a whole day', () => {
        expect(minutesSinceMidnight(new Date('2026-10-07T04:00:00Z'), 'America/New_York')).toBe(0);
        expect(minutesSinceMidnight(new Date('2026-10-08T03:59:00Z'), 'America/New_York')).toBe(23 * 60 + 59);
    });

    test('follows the wall clock across a daylight saving change', () => {
        // Clocks go back at 2 AM EDT (06:00 UTC) on Nov 1 2026, so 1:30 AM happens twice.
        expect(minutesSinceMidnight(new Date('2026-11-01T05:30:00Z'), 'America/New_York')).toBe(90); // 1:30 AM EDT
        expect(minutesSinceMidnight(new Date('2026-11-01T06:30:00Z'), 'America/New_York')).toBe(90); // 1:30 AM EST
        expect(minutesSinceMidnight(new Date('2026-11-01T07:30:00Z'), 'America/New_York')).toBe(150); // 2:30 AM EST
    });
});

describe('where the day view opens', () => {
    test('shows a couple of hours before now, so what is next is in view', () => {
        expect(calendarScrollTop(15 * 60, 56)).toBe((15 - 2.5) * 56);
    });

    test('does not scroll above the top of the day early in the morning', () => {
        expect(calendarScrollTop(60, 56)).toBe(0);
        expect(calendarScrollTop(0, 56)).toBe(0);
    });

    test('opens at 7 AM on a week that does not contain today', () => {
        expect(calendarScrollTop(null, 56)).toBe(7 * 56);
    });
});
