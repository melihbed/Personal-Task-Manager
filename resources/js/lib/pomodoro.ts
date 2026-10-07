import { request } from './http';

export type TimerKind = 'focus' | 'short_break' | 'long_break';
export type Settings = { focus_minutes: number; short_break_minutes: number; long_break_minutes: number; rounds_before_long: number; sound_enabled: boolean };
export type ActiveTimer = { id: number; kind: TimerKind; status: 'running' | 'paused'; task_id: number | null; task_title: string | null; planned_seconds: number; remaining_seconds: number };
export type TimerState = { settings: Settings; active: ActiveTimer | null; cycle: { done: number; of: number; next_break: 'short_break' | 'long_break' };
    /** The round or break that ended within the last hour. */
    last_completed: { kind: TimerKind; ended_at: string } | null;
    server_now: string;
};
export type Stats = {
    today: { date: string; rounds: number; minutes: number };
    week: { date: string; rounds: number; minutes: number }[];
    streak: number;
    total_rounds: number;
    recent: { id: number; task_title: string | null; minutes: number; ended_at: string }[];
};

export const kindLabels: Record<TimerKind, string> = { focus: 'Focus', short_break: 'Short break', long_break: 'Long break' };

export const loadState = () => request<TimerState>('GET', '/pomodoro');
export const startTimer = (kind: TimerKind, taskId: number | null) => request<TimerState>('POST', '/pomodoro', { kind, task_id: taskId });
export const controlTimer = (id: number, action: 'pause' | 'resume' | 'complete' | 'abandon') => request<TimerState>('POST', `/pomodoro/${id}/${action}`);
export const saveSettings = (settings: Settings) => request<TimerState>('PATCH', '/pomodoro/settings', settings);
export const loadStats = (timezone: string) => request<Stats>('GET', `/pomodoro/stats?timezone=${encodeURIComponent(timezone)}`);

/** 3000 seconds reads as "50:00"; an hour or more as "1:05:00". */
export function formatClock(seconds: number): string {
    const total = Math.max(0, Math.ceil(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = String(total % 60).padStart(2, '0');

    return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}` : `${String(minutes).padStart(2, '0')}:${rest}`;
}

/**
 * How many seconds are left right now. The server sent `remaining_seconds` at `receivedAt`; a running timer has been
 * counting down since, and a paused one has not moved.
 */
export function secondsLeft(active: ActiveTimer, receivedAt: number, now: number): number {
    if (active.status === 'paused') return active.remaining_seconds;

    return Math.max(0, active.remaining_seconds - (now - receivedAt) / 1000);
}

/** The label under the clock for the break that comes next. */
export function breakLabel(state: TimerState): string {
    const long = state.cycle.next_break === 'long_break';
    const minutes = long ? state.settings.long_break_minutes : state.settings.short_break_minutes;

    return `${minutes} minute ${long ? 'long break' : 'break'}`;
}
