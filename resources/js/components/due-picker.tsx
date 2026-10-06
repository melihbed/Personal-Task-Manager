import { useState } from 'react';
import { dueDay, formatDue } from '../lib/deadlines';
import { addDays, dateLabel, localToISO, zonedParts } from '../lib/planner';
import Popover from './ui/popover';

export type Due = { dueAt: string; hasTime: boolean };

type Props = {
    value: Due | null;
    onChange: (value: Due | null) => void;
    timezone: string;
};

/** A date-only deadline is stored as noon UTC on that date; see lib/deadlines.ts. */
const dateOnly = (date: string): Due => ({ dueAt: `${date}T12:00:00Z`, hasTime: false });
const timed = (date: string, time: string, timezone: string): Due => ({ dueAt: localToISO(date, time, timezone), hasTime: true });
const inMinutes = (minutes: number): Due => ({ dueAt: new Date(Math.ceil((Date.now() + minutes * 60000) / 60000) * 60000).toISOString(), hasTime: true });

function dayPicks(timezone: string): { label: string; date: string }[] {
    const today = zonedParts(new Date(), timezone).date;
    const weekday = new Date(`${today}T12:00:00Z`).getUTCDay();

    return [
        { label: 'Today', date: today },
        { label: 'Tomorrow', date: addDays(today, 1) },
        { label: 'This weekend', date: addDays(today, (6 - weekday + 7) % 7 || 7) },
        { label: 'Next week', date: addDays(today, (1 - weekday + 7) % 7 || 7) },
    ];
}

export default function DuePicker({ value, onChange, timezone }: Props) {
    const [error, setError] = useState('');
    const date = value ? dueDay({ due_at: value.dueAt, due_has_time: value.hasTime, completed_at: null }, timezone) : null;
    const time = value?.hasTime ? zonedParts(new Date(value.dueAt), timezone).time : '';

    function apply(next: () => Due) {
        try {
            onChange(next());
            setError('');
        } catch (caught) {
            setError((caught as Error).message);
        }
    }

    return (
        <Popover
            ariaLabel="Due date"
            active={!!value}
            label={
                <>
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M5 21V4m0 0h11l-2 4 2 4H5" /></svg>
                    {value ? formatDue(value.dueAt, value.hasTime, timezone) : 'Due'}
                </>
            }
        >
            {(close) => (
                <>
                    {dayPicks(timezone).map((pick) => (
                        <button key={pick.label} type="button" onClick={() => { onChange(dateOnly(pick.date)); close(); }} className="pm-popover-item flex items-center justify-between">
                            <span>{pick.label}</span>
                            <span className="text-xs text-[var(--pm-muted)]">{dateLabel(pick.date, { weekday: 'short', month: 'short', day: 'numeric' })}</span>
                        </button>
                    ))}
                    <button type="button" onClick={() => { onChange(inMinutes(30)); close(); }} className="pm-popover-item">In 30 min</button>
                    <button type="button" onClick={() => { onChange(inMinutes(60)); close(); }} className="pm-popover-item">In 1 hour</button>

                    <div className="mt-1 grid grid-cols-2 gap-2 border-t border-[var(--pm-border)] px-2.5 pt-3 pb-1">
                        <div>
                            <label htmlFor="due-date" className="text-xs font-medium">Date</label>
                            <input
                                id="due-date"
                                type="date"
                                value={date ?? ''}
                                onChange={(event) => {
                                    const picked = event.target.value;
                                    if (picked) apply(() => (time ? timed(picked, time, timezone) : dateOnly(picked)));
                                }}
                                className="pm-input pm-input--flush mt-1.5 px-2.5 py-2 text-xs"
                            />
                        </div>
                        <div>
                            <label htmlFor="due-time" className="text-xs font-medium">Time <span className="font-normal text-[var(--pm-muted)]">(optional)</span></label>
                            <input
                                id="due-time"
                                type="time"
                                value={time}
                                onChange={(event) => {
                                    const picked = event.target.value;
                                    const day = date ?? zonedParts(new Date(), timezone).date;
                                    apply(() => (picked ? timed(day, picked, timezone) : dateOnly(day)));
                                }}
                                className="pm-input pm-input--flush mt-1.5 px-2.5 py-2 text-xs"
                            />
                        </div>
                        <p className="col-span-2 text-[11px] text-[var(--pm-muted)]">{timezone.replaceAll('_', ' ')}</p>
                        {error && <p role="alert" className="col-span-2 text-xs text-red-700">{error}</p>}
                    </div>
                    {value && (
                        <button type="button" onClick={() => { onChange(null); setError(''); close(); }} className="pm-popover-item mt-1 text-[var(--pm-muted)]">Clear deadline</button>
                    )}
                </>
            )}
        </Popover>
    );
}
