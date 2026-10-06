import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { localToISO, overlaps, timeLabel, zonedParts, type PlannerSession, type PlannerTask } from '../lib/planner';

type Props = { task: PlannerTask; date: string; timezone: string; sessions: PlannerSession[]; session?: PlannerSession; onClose: () => void };

export default function ScheduleDialog({ task, date, timezone, sessions, session, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const initialStart = session ? zonedParts(new Date(session.starts_at), timezone) : { date, time: '09:00' };
    const initialEnd = session ? zonedParts(new Date(session.ends_at), timezone) : { date, time: '10:00' };
    const [day, setDay] = useState(initialStart.date);
    const [endDay, setEndDay] = useState(initialEnd.date);
    const [startTime, setStartTime] = useState(initialStart.time);
    const [endTime, setEndTime] = useState(initialEnd.time);
    const [allowOverlap, setAllowOverlap] = useState(false);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    let start = '', end = '', conversionError = '';
    try { start = localToISO(day, startTime, timezone); end = localToISO(endDay, endTime, timezone); } catch (error) { conversionError = (error as Error).message; }
    const conflicts = start && end ? sessions.filter(s => s.id !== session?.id && overlaps(start, end, s.starts_at, s.ends_at)) : [];
    const needsConfirmation = conflicts.length > 0 || !!errors.allow_overlap;
    useEffect(() => { dialog.current?.showModal(); }, []);
    function change() { setErrors({}); setAllowOverlap(false); }
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (conversionError) { setErrors({ starts_at: conversionError }); return; }
        if (Date.parse(end) <= Date.parse(start)) { setErrors({ ends_at: 'End time must be after start time.' }); return; }
        if (needsConfirmation && !allowOverlap) { setErrors({ allow_overlap: 'Confirm the overlap before saving.' }); return; }
        setSaving(true);
        const payload = { starts_at: start, ends_at: end, allow_overlap: allowOverlap };
        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (values: Record<string, string>) => setErrors(values),
            onFinish: () => setSaving(false),
        };
        if (session) router.patch(`/calendar-sessions/${session.id}`, payload, options);
        else router.post(`/tasks/${task.id}/calendar-sessions`, payload, options);
    }
    return (
        <dialog ref={dialog} onCancel={event => { if (saving) event.preventDefault(); else onClose(); }} onClose={onClose} className="pm-dialog" aria-labelledby="schedule-title">
            <form onSubmit={submit} className="space-y-5">
                <div className="flex items-start justify-between gap-4"><div><h2 id="schedule-title" className="text-xl font-medium">{session ? 'Edit work session' : 'Schedule time to work'}</h2><p className="mt-2 text-sm break-words text-[var(--pm-muted)]">{task.title}</p></div><button type="button" disabled={saving} onClick={onClose} aria-label="Close scheduling form" className="pm-button pm-button--secondary pm-button--icon">×</button></div>
                <p className="text-xs text-[var(--pm-muted)]">Times are in {timezone.replaceAll('_', ' ')}. Scheduling does not change the task’s deadline.</p>
                <div><label htmlFor="session-date" className="text-sm font-medium">Start date</label><input autoFocus id="session-date" type="date" required value={day} onChange={e => { if (endDay === day) setEndDay(e.target.value); setDay(e.target.value); change(); }} className="pm-input" /></div>
                <div><label htmlFor="session-end-date" className="text-sm font-medium">End date</label><input id="session-end-date" type="date" required value={endDay} onChange={e => { setEndDay(e.target.value); change(); }} className="pm-input" /></div>
                <div className="grid grid-cols-2 gap-4"><div><label htmlFor="session-start" className="text-sm font-medium">Start</label><input id="session-start" type="time" required step={900} value={startTime} onChange={e => { setStartTime(e.target.value); change(); }} className="pm-input" /></div><div><label htmlFor="session-end" className="text-sm font-medium">End</label><input id="session-end" type="time" required step={900} value={endTime} onChange={e => { setEndTime(e.target.value); change(); }} className="pm-input" /></div></div>
                {needsConfirmation && <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"><p className="font-medium">This time overlaps another session.</p>{conflicts.length > 0 && <ul className="mt-2 space-y-1">{conflicts.map(s => <li key={s.id}>{s.title} · {timeLabel(s.starts_at, timezone)}–{timeLabel(s.ends_at, timezone)}</li>)}</ul>}<label className="mt-3 flex items-start gap-2"><input type="checkbox" checked={allowOverlap} onChange={e => setAllowOverlap(e.target.checked)} className="mt-1" /><span>Save despite overlap</span></label></div>}
                {Object.values(errors).map((error, i) => <p key={i} role="alert" className="text-sm text-red-700">{error}</p>)}
                <div className="pm-button-group justify-end"><button type="button" disabled={saving} onClick={onClose} className="pm-button pm-button--secondary">Cancel</button><button type="submit" disabled={saving} className="pm-button">{saving ? 'Saving…' : 'Save session'}</button></div>
            </form>
        </dialog>
    );
}
