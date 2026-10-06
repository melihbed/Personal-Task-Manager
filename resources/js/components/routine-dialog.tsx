import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { addDays, dateLabel, zonedParts, type PlannerRoutine } from '../lib/planner';
import { dayPresets, formatLength, routineSummary, weekdays } from '../lib/routines';
import Button from './ui/button';
import Field from './ui/field';

type Props = {
    /** Omit to create a new routine. */
    routine?: PlannerRoutine;
    responsibilities: { id: number; name: string }[];
    /** The calendar timezone. A new routine is created in it; an existing one keeps its own. */
    timezone: string;
    onClose: () => void;
};

const lengths = [15, 30, 45, 60, 90, 120, 180, 240];

export default function RoutineDialog({ routine, responsibilities, timezone, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const routineTimezone = routine?.timezone ?? timezone;
    const { data, setData, post, patch, processing, errors } = useForm<{
        title: string;
        responsibility_id: number | null;
        days: number[];
        start_time: string;
        duration_minutes: number;
        timezone: string;
        starts_on: string;
        ends_on: string | null;
    }>({
        title: routine?.title ?? '',
        responsibility_id: routine?.responsibility_id ?? null,
        days: routine?.days ?? [],
        start_time: routine?.start_time ?? '09:00',
        duration_minutes: routine?.duration_minutes ?? 60,
        timezone: routineTimezone,
        starts_on: routine?.starts_on ?? zonedParts(new Date(), timezone).date,
        ends_on: routine?.ends_on ?? null,
    });

    useEffect(() => { dialog.current?.showModal(); }, []);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (routine) patch(`/routines/${routine.id}`, options);
        else post('/routines', options);
    }

    function remove() {
        if (!routine) return;

        setDeleting(true);
        router.delete(`/routines/${routine.id}`, { preserveScroll: true, onSuccess: () => onClose(), onFinish: () => setDeleting(false) });
    }

    function restore(date: string) {
        if (!routine) return;

        router.patch(`/routines/${routine.id}/occurrences/${date}`, { skipped: false }, { preserveScroll: true });
    }

    function toggleDay(day: number) {
        setData('days', data.days.includes(day) ? data.days.filter((selected) => selected !== day) : [...data.days, day].sort((a, b) => a - b));
    }

    const busy = processing || deleting;
    const messages = Object.values(errors);
    const durationOptions = lengths.includes(data.duration_minutes) ? lengths : [...lengths, data.duration_minutes].sort((a, b) => a - b);
    const summary = data.days.length
        ? routineSummary(
            { days: data.days, start_time: data.start_time, duration_minutes: data.duration_minutes, ends_on: data.ends_on },
            data.ends_on ? dateLabel(data.ends_on, { month: 'short', day: 'numeric', year: 'numeric' }) : undefined,
        )
        : 'Choose the days this repeats on.';

    return (
        <dialog
            ref={dialog}
            aria-labelledby="routine-title"
            className="pm-dialog"
            onCancel={(event) => { if (busy) event.preventDefault(); else onClose(); }}
            onClose={onClose}
        >
            <form onSubmit={submit} className="space-y-5">
                <h2 id="routine-title" className="text-xl font-medium">{routine ? 'Edit routine' : 'New routine'}</h2>

                <Field
                    id="routine-name"
                    label="Name"
                    value={data.title}
                    onChange={(event) => setData('title', event.target.value)}
                    placeholder="Prepare breakfast for students"
                    maxLength={255}
                    error={errors.title}
                    autoFocus
                    required
                />

                <div>
                    <label htmlFor="routine-responsibility" className="text-sm font-medium">Responsibility</label>
                    <select
                        id="routine-responsibility"
                        value={data.responsibility_id ?? ''}
                        onChange={(event) => setData('responsibility_id', event.target.value === '' ? null : Number(event.target.value))}
                        className="pm-input cursor-pointer"
                    >
                        <option value="">Inbox</option>
                        {responsibilities.map((responsibility) => (
                            <option key={responsibility.id} value={responsibility.id}>{responsibility.name}</option>
                        ))}
                    </select>
                </div>

                <fieldset>
                    <legend className="text-sm font-medium">Repeats on</legend>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {weekdays.map((weekday) => {
                            const selected = data.days.includes(weekday.iso);

                            return (
                                <button
                                    key={weekday.iso}
                                    type="button"
                                    aria-pressed={selected}
                                    aria-label={weekday.name}
                                    title={weekday.name}
                                    onClick={() => toggleDay(weekday.iso)}
                                    className={`size-10 cursor-pointer rounded-full border text-sm font-medium transition-colors ${selected ? 'border-[var(--pm-text)] bg-[var(--pm-text)] text-white' : 'border-[var(--pm-border)] bg-[var(--pm-surface)] hover:bg-[var(--pm-background)]'}`}
                                >
                                    {weekday.letter}
                                </button>
                            );
                        })}
                    </div>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {dayPresets.map((preset) => (
                            <button key={preset.label} type="button" onClick={() => setData('days', preset.days)} className="pm-chip">{preset.label}</button>
                        ))}
                    </div>
                    {errors.days && <p role="alert" className="mt-1 text-sm text-red-700">{errors.days}</p>}
                </fieldset>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label htmlFor="routine-time" className="text-sm font-medium">Starts at</label>
                        <input
                            id="routine-time"
                            type="time"
                            required
                            value={data.start_time}
                            onChange={(event) => setData('start_time', event.target.value)}
                            className="pm-input"
                        />
                    </div>
                    <div>
                        <label htmlFor="routine-length" className="text-sm font-medium">Length</label>
                        <select
                            id="routine-length"
                            value={data.duration_minutes}
                            onChange={(event) => setData('duration_minutes', Number(event.target.value))}
                            className="pm-input cursor-pointer"
                        >
                            {durationOptions.map((minutes) => (
                                <option key={minutes} value={minutes}>{formatLength(minutes)}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="routine-starts" className="text-sm font-medium">Starting</label>
                        <input
                            id="routine-starts"
                            type="date"
                            required
                            value={data.starts_on}
                            onChange={(event) => setData('starts_on', event.target.value)}
                            className="pm-input"
                        />
                    </div>
                    <fieldset>
                        <legend className="text-sm font-medium">Ends</legend>
                        <div className="mt-2 space-y-2 text-sm">
                            <label className="flex cursor-pointer items-center gap-2">
                                <input type="radio" name="routine-ends" checked={data.ends_on === null} onChange={() => setData('ends_on', null)} className="accent-[var(--pm-text)]" />
                                Never
                            </label>
                            <label className="flex cursor-pointer items-center gap-2">
                                <input
                                    type="radio"
                                    name="routine-ends"
                                    checked={data.ends_on !== null}
                                    onChange={() => setData('ends_on', addDays(data.starts_on, 90))}
                                    className="accent-[var(--pm-text)]"
                                />
                                On date
                            </label>
                        </div>
                    </fieldset>
                </div>
                {data.ends_on !== null && (
                    <div>
                        <label htmlFor="routine-ends-on" className="text-sm font-medium">Last day</label>
                        <input
                            id="routine-ends-on"
                            type="date"
                            min={data.starts_on}
                            value={data.ends_on}
                            onChange={(event) => setData('ends_on', event.target.value)}
                            className="pm-input"
                        />
                    </div>
                )}

                <p className="rounded-xl bg-[var(--pm-background)] px-3 py-2 text-sm" aria-live="polite">
                    {summary}
                    {routineTimezone !== timezone && <span className="block text-xs text-[var(--pm-muted)]">Times are in {routineTimezone.replaceAll('_', ' ')}.</span>}
                </p>

                {routine && routine.skipped_dates.length > 0 && (
                    <div>
                        <p className="text-sm font-medium">Skipped days</p>
                        <ul className="mt-2 space-y-1">
                            {routine.skipped_dates.map((date) => (
                                <li key={date} className="flex items-center justify-between gap-3 text-sm">
                                    <span>{dateLabel(date, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}</span>
                                    <Button type="button" variant="secondary" size="small" onClick={() => restore(date)}>Restore</Button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {messages.filter((message) => message !== errors.title && message !== errors.days).map((message, index) => (
                    <p key={index} role="alert" className="text-sm text-red-700">{message}</p>
                ))}

                {confirmingDelete ? (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm">
                        <p className="font-medium text-red-900">Delete this routine?</p>
                        <p className="mt-1 text-red-800">Every event is removed from your calendar. This cannot be undone.</p>
                        <div className="pm-button-group mt-3">
                            <Button type="button" variant="secondary" size="small" disabled={deleting} onClick={() => setConfirmingDelete(false)}>Keep it</Button>
                            <Button type="button" variant="danger" size="small" loading={deleting} loadingLabel="Deleting routine" onClick={remove}>Delete routine</Button>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        {routine ? (
                            <Button type="button" variant="danger" disabled={busy} onClick={() => setConfirmingDelete(true)}>Delete routine</Button>
                        ) : <span />}
                        <div className="pm-button-group">
                            <Button type="button" variant="secondary" disabled={busy} onClick={onClose}>Cancel</Button>
                            <Button type="submit" loading={processing} loadingLabel="Saving routine" disabled={data.title.trim() === '' || data.days.length === 0 || deleting}>
                                {routine ? 'Save' : 'Add routine'}
                            </Button>
                        </div>
                    </div>
                )}
            </form>
        </dialog>
    );
}
