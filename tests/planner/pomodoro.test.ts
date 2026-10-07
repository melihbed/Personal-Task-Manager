import { describe, expect, it } from 'vitest';
import { breakLabel, formatClock, secondsLeft, type ActiveTimer, type TimerState } from '../../resources/js/lib/pomodoro';

const timer = (overrides: Partial<ActiveTimer> = {}): ActiveTimer => ({ id: 1, kind: 'focus', status: 'running', task_id: null, task_title: null, planned_seconds: 3000, remaining_seconds: 1000, ...overrides });

describe('formatClock', () => {
    it('shows minutes and seconds, and hours only when needed', () => {
        expect(formatClock(3000)).toBe('50:00');
        expect(formatClock(65)).toBe('01:05');
        expect(formatClock(0)).toBe('00:00');
        expect(formatClock(3725)).toBe('1:02:05');
    });

    it('rounds a fraction of a second up, so the clock never shows 00:00 early', () => {
        expect(formatClock(0.2)).toBe('00:01');
        expect(formatClock(59.01)).toBe('01:00');
    });

    it('never goes below zero', () => {
        expect(formatClock(-5)).toBe('00:00');
    });
});

describe('secondsLeft', () => {
    it('counts a running timer down from when the server reported it', () => {
        expect(secondsLeft(timer(), 10_000, 14_000)).toBe(996);
    });

    it('stops at zero', () => {
        expect(secondsLeft(timer(), 0, 5_000_000)).toBe(0);
    });

    it('does not move a paused timer', () => {
        expect(secondsLeft(timer({ status: 'paused' }), 0, 900_000)).toBe(1000);
    });
});

describe('breakLabel', () => {
    const state = (next_break: 'short_break' | 'long_break'): TimerState => ({
        settings: { focus_minutes: 50, short_break_minutes: 10, long_break_minutes: 30, rounds_before_long: 3, sound_enabled: true },
        active: null, cycle: { done: 0, of: 3, next_break }, last_completed: null, server_now: '',
    });

    it('names the break that comes next', () => {
        expect(breakLabel(state('short_break'))).toBe('10 minute break');
        expect(breakLabel(state('long_break'))).toBe('30 minute long break');
    });
});
