import { useEffect, useRef, useState } from 'react';
import { addDays, dateLabel, dayLayout, overlaps, timeLabel, zonedParts, type PlannerSession } from '../lib/planner';

type Props = { weekStart: string; timezone: string; sessions: PlannerSession[]; onSelect: (session: PlannerSession) => void };

export default function WeeklyCalendar({ weekStart, timezone, sessions, onSelect }: Props) {
    const today = zonedParts(new Date(), timezone).date;
    const dates = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
    const [selected, setSelected] = useState(today >= weekStart && today <= dates[6] ? today : weekStart);
    const viewport = useRef<HTMLDivElement>(null);
    const conflicts = new Set(sessions.filter(a => sessions.some(b => a.id !== b.id && overlaps(a.starts_at, a.ends_at, b.starts_at, b.ends_at))).map(s => s.id));
    useEffect(() => {
        setSelected(today >= weekStart && today <= addDays(weekStart, 6) ? today : weekStart);
        if (viewport.current) viewport.current.scrollTop = 7 * 56;
    }, [weekStart, today]);

    return (
        <div className="overflow-hidden rounded-2xl border border-[var(--pm-border)] bg-white">
            <div className="flex border-b border-[var(--pm-border)]">
                <div className="w-14 shrink-0" />
                {dates.map(date => <button key={date} type="button" onClick={() => setSelected(date)} aria-pressed={selected === date} className={`pm-calendar-day min-w-0 flex-1 px-1 py-3 text-center ${selected === date ? 'bg-[var(--pm-background)] md:bg-transparent' : ''}`}><span className="block text-[10px] text-[var(--pm-muted)]">{dateLabel(date, { weekday: 'short' })}</span><span className={`mx-auto mt-1 flex h-7 w-7 items-center justify-center rounded-full text-sm ${date === today ? 'bg-[var(--pm-text)] text-white' : ''}`}>{dateLabel(date, { day: 'numeric' })}</span></button>)}
            </div>
            <div ref={viewport} className="pm-calendar-scroll overflow-y-auto" style={{ height: 540 }}>
                <div className="relative flex" style={{ height: 24 * 56 }}>
                    <div className="relative w-14 shrink-0 bg-white">
                        {Array.from({ length: 24 }, (_, hour) => <span key={hour} className="absolute right-2 text-[10px] text-[var(--pm-muted)]" style={{ top: hour * 56 + 3 }}>{hour === 0 ? '12 AM' : hour < 12 ? `${hour} AM` : hour === 12 ? '12 PM' : `${hour - 12} PM`}</span>)}
                    </div>
                    {dates.map(date => (
                        <div key={date} className={`relative min-w-0 flex-1 border-l border-[var(--pm-border)] ${selected === date ? 'block' : 'hidden md:block'} ${date === today ? 'bg-orange-50/30' : ''}`}>
                            {Array.from({ length: 24 }, (_, hour) => <div key={hour} className="absolute inset-x-0 border-t border-[var(--pm-border)]" style={{ top: hour * 56 }}><div className="absolute inset-x-0 border-t border-dashed border-[var(--pm-border)] opacity-40" style={{ top: 28 }} /></div>)}
                            {dayLayout(sessions, date, timezone).map(({ session, start, end, lane, laneCount }) => (
                                <button key={session.id} type="button" onClick={() => onSelect(session)} title={`${session.title} · ${timeLabel(session.starts_at, timezone)}–${timeLabel(session.ends_at, timezone)}${conflicts.has(session.id) ? ' · Overlap' : ''}`} aria-label={`${session.title}, ${timeLabel(session.starts_at, timezone)} to ${timeLabel(session.ends_at, timezone)}${conflicts.has(session.id) ? ', overlaps another session' : ''}`} className={`pm-calendar-session absolute overflow-hidden rounded-lg border px-1.5 py-1 text-left text-[10px] leading-tight ${session.completed ? 'bg-slate-100 text-[var(--pm-muted)]' : 'bg-[#f6e8df] text-[var(--pm-text)]'} ${conflicts.has(session.id) ? 'border-amber-600' : 'border-[#e7c5b2]'}`} style={{ top: start / 60 * 56, height: Math.max(18, (end - start) / 60 * 56 - 2), left: `calc(${lane / laneCount * 100}% + 2px)`, width: `calc(${100 / laneCount}% - 4px)`, borderLeftWidth: 3, borderLeftColor: conflicts.has(session.id) ? '#b45309' : session.color ?? 'var(--pm-accent)' }}>
                                    <span className={`block truncate font-semibold ${session.completed ? 'line-through' : ''}`}>{session.title}</span>
                                    {end - start >= 40 && <span className="mt-1 block truncate">{timeLabel(session.starts_at, timezone)}</span>}
                                    {end - start >= 75 && <span className="mt-1 block truncate opacity-70">{session.responsibility_name}</span>}
                                    {conflicts.has(session.id) && end - start >= 55 && <span className="mt-1 block font-medium text-amber-800">Overlap</span>}
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--pm-border)] px-4 py-3 text-xs text-[var(--pm-muted)]"><span>{sessions.length} work {sessions.length === 1 ? 'session' : 'sessions'} this week</span><span>{conflicts.size ? `${conflicts.size} overlapping sessions` : 'No overlaps'} · {timezone.replaceAll('_', ' ')}</span></div>
        </div>
    );
}
