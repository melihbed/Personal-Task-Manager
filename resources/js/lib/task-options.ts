export type Priority = 'low' | 'normal' | 'high';

/** The lengths offered when saying how long a task will take. */
export const durations = [
    { minutes: 5, label: '5 min' },
    { minutes: 15, label: '15 min' },
    { minutes: 30, label: '30 min' },
    { minutes: 60, label: '1 hour' },
    { minutes: 90, label: '1.5 hours' },
    { minutes: 120, label: '2 hours' },
];

export const priorityLabels: Record<Priority, string> = { low: 'Low priority', normal: 'Normal priority', high: 'High priority' };

/** The offered lengths, plus the task's own length when it is not one of them (for example one copied from Google). */
export function durationOptions(current: number | null): { minutes: number; label: string }[] {
    if (current === null || durations.some(duration => duration.minutes === current)) return durations;

    return [...durations, { minutes: current, label: `${current} min` }].sort((a, b) => a.minutes - b.minutes);
}
