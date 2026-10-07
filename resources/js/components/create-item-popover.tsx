import { router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState, type FormEvent } from 'react';
import { addDays, dateLabel, localToISO } from '../lib/planner';
import { clock24 } from '../lib/calendar-moves';
import Button from './ui/button';

type Kind = 'task' | 'event' | 'routine';
type Props = {
    range: { date: string; start: number; end: number };
    /** Where the chosen range is on screen, so the form can sit beside it. */
    anchor: DOMRect;
    timezone: string;
    responsibilities: { id: number; name: string }[];
    onClose: () => void;
};

const WIDTH = 392;
const kinds: { value: Kind; label: string }[] = [{ value: 'task', label: 'Task' }, { value: 'event', label: 'Event' }, { value: 'routine', label: 'Routine' }];
const weekdays = [{ day: 1, label: 'Mon' }, { day: 2, label: 'Tue' }, { day: 3, label: 'Wed' }, { day: 4, label: 'Thu' }, { day: 5, label: 'Fri' }, { day: 6, label: 'Sat' }, { day: 7, label: 'Sun' }];
const isoWeekday = (date: string) => new Date(`${date}T12:00:00Z`).getUTCDay() || 7;
const firstError = (errors: Record<string, string>) => Object.values(errors)[0];

/**
 * The form that opens beside a range dragged on the calendar, like Google Calendar's: a title, then what it is. A task can
 * reserve that time for working on it; an event is a block of time with an optional place; a routine repeats on chosen weekdays.
 */
export default function CreateItemPopover({ range, anchor, timezone, responsibilities, onClose }: Props) {
    const panel = useRef<HTMLFormElement>(null);
    const [kind, setKind] = useState<Kind>('task');
    const [title, setTitle] = useState('');
    const [date, setDate] = useState(range.date);
    const [startTime, setStartTime] = useState(clock24(range.start));
    const [endDate, setEndDate] = useState(range.end >= 1440 ? addDays(range.date, 1) : range.date);
    const [endTime, setEndTime] = useState(clock24(range.end % 1440));
    const [reserve, setReserve] = useState(true);
    const [deadline, setDeadline] = useState(false);
    const [responsibilityId, setResponsibilityId] = useState<number | null>(null);
    const [days, setDays] = useState<number[]>([isoWeekday(range.date)]);
    const [endsOn, setEndsOn] = useState('');
    const [location, setLocation] = useState('');
    const [allowOverlap, setAllowOverlap] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [position, setPosition] = useState<{ left: number; top: number }>({ left: 12, top: 12 });

    useEffect(() => { panel.current?.querySelector<HTMLInputElement>('#create-title')?.focus(); }, []);

    // Beside the chosen range, on whichever side has room, and never off the screen.
    useLayoutEffect(() => {
        const height = panel.current?.offsetHeight ?? 420;
        const right = anchor.right + 12;
        const left = right + WIDTH <= window.innerWidth - 12 ? right : Math.max(12, anchor.left - WIDTH - 12);
        const top = Math.max(12, Math.min(anchor.top - 8, window.innerHeight - height - 12));

        setPosition({ left, top });
    }, [anchor, kind, errors]);

    useEffect(() => {
        function closeOnEscape(event: KeyboardEvent) {
            if (event.key === 'Escape' && !saving) onClose();
        }

        document.addEventListener('keydown', closeOnEscape);

        return () => document.removeEventListener('keydown', closeOnEscape);
    }, [onClose, saving]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (title.trim() === '') { setErrors({ title: 'Give it a title.' }); return; }

        let startsAt: string;
        let endsAt: string;

        try {
            startsAt = localToISO(date, startTime, timezone);
            endsAt = localToISO(endDate, endTime, timezone);
        } catch (problem) {
            setErrors({ starts_at: (problem as Error).message });
            return;
        }

        if (Date.parse(endsAt) <= Date.parse(startsAt)) { setErrors({ ends_at: 'The end must be after the start.' }); return; }

        const payload: Record<string, string | number | boolean | number[] | null> = { type: kind, title: title.trim(), starts_at: startsAt, ends_at: endsAt, timezone };

        if (kind === 'task') Object.assign(payload, { reserve, deadline_at_end: deadline, responsibility_id: responsibilityId, allow_overlap: allowOverlap });
        if (kind === 'event') Object.assign(payload, { location: location.trim() === '' ? null : location.trim(), responsibility_id: responsibilityId });
        if (kind === 'routine') Object.assign(payload, { days, responsibility_id: responsibilityId, ends_on: endsOn === '' ? null : endsOn });

        setSaving(true);
        setErrors({});
        router.post('/calendar/items', payload, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (problems) => setErrors(problems),
            onFinish: () => setSaving(false),
        });
    }

    const needsOverlapOk = !!errors.allow_overlap;
    const other = Object.entries(errors).filter(([field]) => field !== 'allow_overlap').map(([, message]) => message);
    const cannotSave = saving;

    return (
        <form
            ref={panel}
            onSubmit={submit}
            role="dialog"
            aria-label="New item"
            className="fixed z-50 rounded-2xl border border-[var(--pm-border)] bg-white p-5 shadow-[0_16px_48px_#252b3d33]"
            style={{ left: position.left, top: position.top, width: `min(${WIDTH}px, calc(100vw - 24px))` }}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <label htmlFor="create-title" className="sr-only">Title</label>
                    <input
                        id="create-title"
                        value={title}
                        maxLength={255}
                        placeholder="Add title"
                        aria-invalid={!!errors.title}
                        onChange={(event) => { setTitle(event.target.value); if (errors.title) setErrors({}); }}
                        className="w-full border-0 border-b-2 border-[var(--pm-border)] bg-transparent pb-1.5 text-xl outline-none placeholder:text-[var(--pm-muted)] focus:border-[#2563eb]"
                    />
                </div>
                <button type="button" onClick={onClose} aria-label="Close" className="pm-button pm-button--secondary pm-button--small pm-button--icon">×</button>
            </div>

            <div role="tablist" aria-label="What to add" className="pm-tabs mt-4" onKeyDown={(event) => {
                const at = kinds.findIndex(item => item.value === kind);

                if (event.key === 'ArrowRight') setKind(kinds[(at + 1) % kinds.length].value);
                if (event.key === 'ArrowLeft') setKind(kinds[(at + kinds.length - 1) % kinds.length].value);
            }}>
                {kinds.map(item => (
                    <button key={item.value} type="button" role="tab" aria-selected={kind === item.value} tabIndex={kind === item.value ? 0 : -1} onClick={() => { setKind(item.value); setErrors({}); }} className="pm-tab">{item.label}</button>
                ))}
            </div>

            <fieldset className="mt-4">
                <legend className="sr-only">When</legend>
                <div className="space-y-2">
                    <input aria-label="Start date" type="date" required value={date} onChange={(event) => { if (endDate === date) setEndDate(event.target.value); setDate(event.target.value); }} className="pm-input pm-input--flush !py-2 text-sm" />
                    <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                        <input aria-label="Start time" type="time" required step={900} value={startTime} onChange={(event) => setStartTime(event.target.value)} className="pm-input pm-input--flush !py-2 text-sm" />
                        <span aria-hidden="true" className="text-[var(--pm-muted)]">to</span>
                        <input aria-label="End time" type="time" required step={900} value={endTime} onChange={(event) => setEndTime(event.target.value)} className="pm-input pm-input--flush !py-2 text-sm" />
                    </div>
                </div>
                {endDate !== date && <p className="mt-1.5 text-xs text-[var(--pm-muted)]">Ends {dateLabel(endDate, { weekday: 'short', month: 'short', day: 'numeric' })}</p>}
            </fieldset>

            {kind === 'task' && (
                <div className="mt-4 space-y-3">
                    <label className="flex cursor-pointer items-start gap-3 text-sm"><input type="checkbox" checked={reserve} onChange={(event) => setReserve(event.target.checked)} className="mt-0.5 size-4 accent-[var(--pm-text)]" /><span>Reserve this time to work on it<span className="block text-xs text-[var(--pm-muted)]">Adds a work session to your calendar.</span></span></label>
                    <label className="flex cursor-pointer items-start gap-3 text-sm"><input type="checkbox" checked={deadline} onChange={(event) => setDeadline(event.target.checked)} className="mt-0.5 size-4 accent-[var(--pm-text)]" /><span>Make the end its deadline<span className="block text-xs text-[var(--pm-muted)]">Due when this time ends.</span></span></label>
                </div>
            )}

            {kind === 'event' && (
                <div className="mt-4">
                    <label htmlFor="create-location" className="sr-only">Place</label>
                    <input id="create-location" value={location} maxLength={255} placeholder="Add place (optional)" onChange={(event) => setLocation(event.target.value)} className="pm-input pm-input--flush !py-2 text-sm" />
                </div>
            )}

            {kind === 'routine' && (
                <div className="mt-4 space-y-3">
                    <div>
                        <p className="text-sm font-medium">Repeats weekly on</p>
                        <div role="group" aria-label="Days" className="mt-2 flex flex-wrap gap-1.5">
                            {weekdays.map(item => (
                                <button key={item.day} type="button" aria-pressed={days.includes(item.day)} onClick={() => setDays(current => current.includes(item.day) ? current.filter(day => day !== item.day) : [...current, item.day])} className={`pm-chip ${days.includes(item.day) ? 'pm-chip--active' : ''}`}>{item.label}</button>
                            ))}
                        </div>
                    </div>
                    <label className="flex items-center justify-between gap-3 text-sm whitespace-nowrap">Ends on<input type="date" value={endsOn} min={date} onChange={(event) => setEndsOn(event.target.value)} className="pm-input pm-input--flush w-auto !py-2 text-sm" /></label>
                </div>
            )}

            {(
                <label className="mt-4 flex items-center justify-between gap-3 text-sm">
                    Responsibility
                    <select value={responsibilityId ?? ''} onChange={(event) => setResponsibilityId(event.target.value === '' ? null : Number(event.target.value))} className="pm-input pm-input--flush w-auto max-w-48 cursor-pointer !py-2 text-sm">
                        <option value="">Inbox</option>
                        {responsibilities.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </select>
                </label>
            )}

            {needsOverlapOk && (
                <div className="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3.5 text-sm text-amber-950">
                    <p className="font-medium">This time overlaps another work session.</p>
                    <label className="mt-2 flex items-center gap-2"><input type="checkbox" checked={allowOverlap} onChange={(event) => setAllowOverlap(event.target.checked)} className="size-4" />Save despite overlap</label>
                </div>
            )}
            {other.map((message, index) => <p key={index} role="alert" className="mt-3 text-sm text-red-700">{message}</p>)}

            <div className="pm-button-group mt-5 justify-end">
                <Button type="button" variant="secondary" disabled={saving} onClick={onClose}>Cancel</Button>
                <Button type="submit" loading={saving} loadingLabel="Saving" disabled={cannotSave || (needsOverlapOk && !allowOverlap)}>Save</Button>
            </div>
        </form>
    );
}
