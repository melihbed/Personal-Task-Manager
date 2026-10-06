import type { PlannerRoutine } from './planner';

/** ISO weekdays, Monday first to match the calendar. */
export const weekdays = [
    { iso: 1, letter: 'M', short: 'Mon', name: 'Monday' },
    { iso: 2, letter: 'T', short: 'Tue', name: 'Tuesday' },
    { iso: 3, letter: 'W', short: 'Wed', name: 'Wednesday' },
    { iso: 4, letter: 'T', short: 'Thu', name: 'Thursday' },
    { iso: 5, letter: 'F', short: 'Fri', name: 'Friday' },
    { iso: 6, letter: 'S', short: 'Sat', name: 'Saturday' },
    { iso: 7, letter: 'S', short: 'Sun', name: 'Sunday' },
];

export const dayPresets = [
    { label: 'Every day', days: [1, 2, 3, 4, 5, 6, 7] },
    { label: 'Weekdays', days: [1, 2, 3, 4, 5] },
    { label: 'Weekends', days: [6, 7] },
];

const sameDays = (a: number[], b: number[]) => a.length === b.length && a.every((day, index) => day === b[index]);

export function describeDays(days: number[]): string {
    const sorted = [...days].sort((a, b) => a - b);

    if (sorted.length === 0) return 'Pick at least one day';
    if (sameDays(sorted, dayPresets[0].days)) return 'Every day';
    if (sameDays(sorted, dayPresets[1].days)) return 'Every weekday';
    if (sameDays(sorted, dayPresets[2].days)) return 'Every weekend';
    if (sorted.length === 1) return `Every ${weekdays[sorted[0] - 1].name}`;

    return `Every ${sorted.map((day) => weekdays[day - 1].short).join(', ')}`;
}

/** '08:00' -> '8:00 AM' */
export function formatClock(time: string): string {
    const [hours, minutes] = time.split(':').map(Number);

    return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${hours < 12 ? 'AM' : 'PM'}`;
}

export function formatLength(minutes: number): string {
    if (minutes < 60) return `${minutes} min`;

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    const hourText = `${hours} ${hours === 1 ? 'hour' : 'hours'}`;

    return rest === 0 ? hourText : `${hourText} ${rest} min`;
}

type Rule = Pick<PlannerRoutine, 'days' | 'start_time' | 'duration_minutes' | 'ends_on'>;

/** 'Every Tue, Thu at 8:00 AM · 1 hour · Never ends' */
export function routineSummary(rule: Rule, endLabel?: string): string {
    const ends = rule.ends_on ? `Ends ${endLabel ?? rule.ends_on}` : 'Never ends';

    return `${describeDays(rule.days)} at ${formatClock(rule.start_time)} · ${formatLength(rule.duration_minutes)} · ${ends}`;
}
