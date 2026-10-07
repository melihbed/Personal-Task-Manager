import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { eventFormValues } from '../lib/calendar-moves';
import { addDays, dateLabel, localToISO, timeLabel, type PlannerEvent } from '../lib/planner';
import Button from './ui/button';
import ConfirmDialog from './ui/confirm-dialog';
import Field from './ui/field';
import { useToast } from './ui/toast';

type Props = {
    event: PlannerEvent;
    responsibilities: { id: number; name: string; color: string | null }[];
    /** The calendar timezone, used to show and change the event's time. */
    timezone: string;
    onClose: () => void;
};

const dayFormat = { weekday: 'long', month: 'short', day: 'numeric' } as const;

/** When the event happens, in words. */
function describeWhen(event: PlannerEvent, timezone: string): string {
    if (event.all_day && event.start_date && event.end_date) {
        return event.end_date === event.start_date ? `${dateLabel(event.start_date, dayFormat)}, all day` : `${dateLabel(event.start_date, dayFormat)} to ${dateLabel(event.end_date, dayFormat)}`;
    }

    if (event.starts_at && event.ends_at) {
        const values = eventFormValues(event, timezone);
        const day = dateLabel(values.startDate, dayFormat);

        return `${day}, ${timeLabel(event.starts_at, timezone)} – ${timeLabel(event.ends_at, timezone)}${values.endDate !== values.startDate ? ` (until ${dateLabel(values.endDate, { month: 'short', day: 'numeric' })})` : ''}`;
    }

    return '';
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
            <dt className="shrink-0 text-sm text-[var(--pm-muted)]">{label}</dt>
            <dd className="min-w-0 text-right text-sm break-words">{children}</dd>
        </div>
    );
}

/** Change an event's title, place, notes, responsibility and time. It belongs to the planner alone, so only the planner changes. */
function EditForm({ event, responsibilities, timezone, onCancel, onSaved }: Props & { onCancel: () => void; onSaved: () => void }) {
    const [values, setValues] = useState(() => eventFormValues(event, timezone));
    const [title, setTitle] = useState(event.title);
    const [location, setLocation] = useState(event.location ?? '');
    const [notes, setNotes] = useState(event.notes ?? '');
    const [responsibilityId, setResponsibilityId] = useState<number | null>(event.responsibility_id);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const set = (field: keyof typeof values, value: string) => setValues(current => ({ ...current, [field]: value }));

    function submit(submitted: FormEvent<HTMLFormElement>) {
        submitted.preventDefault();
        setError('');

        let times: Record<string, string | boolean>;

        try {
            if (event.all_day) {
                if (values.endDate < values.startDate) throw new Error('The last day cannot be before the first day.');

                times = { all_day: true, start_date: values.startDate, end_date: values.endDate };
            } else {
                const startsAt = localToISO(values.startDate, values.startTime, timezone);
                const endsAt = localToISO(values.endDate, values.endTime, timezone);

                if (Date.parse(endsAt) <= Date.parse(startsAt)) throw new Error('The event must end after it starts.');

                times = { all_day: false, starts_at: startsAt, ends_at: endsAt };
            }
        } catch (problem) {
            setError((problem as Error).message);

            return;
        }

        setSaving(true);
        router.patch(`/events/${event.id}`, { title: title.trim(), location: location.trim() === '' ? null : location.trim(), notes: notes.trim() === '' ? null : notes.trim(), responsibility_id: responsibilityId, ...times }, {
            preserveScroll: true,
            onSuccess: onSaved,
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Could not save. Please try again.'),
            onFinish: () => setSaving(false),
        });
    }

    return (
        <form onSubmit={submit} className="space-y-5">
            <h2 id="event-dialog-title" className="text-xl font-medium">Edit event</h2>

            <Field id="event-title" label="Title" value={title} onChange={(change) => setTitle(change.target.value)} maxLength={255} autoFocus required />

            {event.all_day ? (
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label htmlFor="event-first" className="text-sm font-medium">First day</label>
                        <input id="event-first" type="date" value={values.startDate} onChange={(change) => { if (values.endDate === values.startDate) set('endDate', change.target.value); set('startDate', change.target.value); }} className="pm-input" required />
                    </div>
                    <div>
                        <label htmlFor="event-last" className="text-sm font-medium">Last day</label>
                        <input id="event-last" type="date" min={values.startDate} value={values.endDate} onChange={(change) => set('endDate', change.target.value)} className="pm-input" required />
                    </div>
                </div>
            ) : (
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label htmlFor="event-start-date" className="text-sm font-medium">Starts on</label>
                        <input id="event-start-date" type="date" value={values.startDate} onChange={(change) => { if (values.endDate === values.startDate) set('endDate', change.target.value); set('startDate', change.target.value); }} className="pm-input" required />
                    </div>
                    <div>
                        <label htmlFor="event-start-time" className="text-sm font-medium">Starts at</label>
                        <input id="event-start-time" type="time" step={900} value={values.startTime} onChange={(change) => set('startTime', change.target.value)} className="pm-input" required />
                    </div>
                    <div>
                        <label htmlFor="event-end-date" className="text-sm font-medium">Ends on</label>
                        <input id="event-end-date" type="date" min={values.startDate} value={values.endDate} onChange={(change) => set('endDate', change.target.value)} className="pm-input" required />
                    </div>
                    <div>
                        <label htmlFor="event-end-time" className="text-sm font-medium">Ends at</label>
                        <input id="event-end-time" type="time" step={900} value={values.endTime} onChange={(change) => set('endTime', change.target.value)} className="pm-input" required />
                    </div>
                </div>
            )}
            {!event.all_day && <p className="-mt-2 text-xs text-[var(--pm-muted)]">{timezone.replaceAll('_', ' ')}</p>}

            <Field id="event-location" label="Place (optional)" value={location} onChange={(change) => setLocation(change.target.value)} maxLength={255} />

            <div>
                <label htmlFor="event-notes" className="text-sm font-medium">Notes (optional)</label>
                <textarea id="event-notes" value={notes} maxLength={5000} rows={3} onChange={(change) => setNotes(change.target.value)} className="pm-input resize-y" />
            </div>

            <div>
                <label htmlFor="event-responsibility" className="text-sm font-medium">Responsibility</label>
                <select id="event-responsibility" value={responsibilityId ?? ''} onChange={(change) => setResponsibilityId(change.target.value === '' ? null : Number(change.target.value))} className="pm-input cursor-pointer">
                    <option value="">Inbox</option>
                    {responsibilities.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                </select>
            </div>

            {error && <p role="alert" className="text-sm text-red-700">{error}</p>}

            <div className="pm-button-group justify-end">
                <Button type="button" variant="secondary" disabled={saving} onClick={onCancel}>Cancel</Button>
                <Button type="submit" loading={saving} loadingLabel="Saving" disabled={title.trim() === ''}>Save</Button>
            </div>
        </form>
    );
}

/** One of the app's own events, opened from the calendar: its details, and the ways to change or delete it. */
export default function EventDialog({ event, responsibilities, timezone, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const toast = useToast();
    const [mode, setMode] = useState<'view' | 'edit'>('view');
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const responsibility = responsibilities.find(item => item.id === event.responsibility_id) ?? null;

    useEffect(() => { dialog.current?.showModal(); }, []);

    function remove() {
        setBusy(true);
        router.delete(`/events/${event.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.show({ message: `Deleted “${event.title}”.` });
                onClose();
            },
            onError: () => toast.show({ message: 'Could not delete that event. Please try again.' }),
            onFinish: () => { setBusy(false); setConfirming(false); },
        });
    }

    return (
        <>
            <dialog ref={dialog} aria-labelledby="event-dialog-title" className="pm-dialog" onCancel={(cancel) => { if (busy) cancel.preventDefault(); else onClose(); }} onClose={onClose}>
                {mode === 'edit' ? (
                    <EditForm event={event} responsibilities={responsibilities} timezone={timezone} onClose={onClose} onCancel={() => setMode('view')} onSaved={onClose} />
                ) : (
                    <>
                        <div className="flex items-start justify-between gap-4">
                            <h2 id="event-dialog-title" className="text-xl font-medium break-words">{event.title}</h2>
                            <button type="button" disabled={busy} onClick={onClose} aria-label="Close event" className="pm-button pm-button--secondary pm-button--icon">×</button>
                        </div>

                        <p className="mt-3 font-medium">{describeWhen(event, timezone)}</p>

                        <dl className="mt-4 divide-y divide-[var(--pm-border)]">
                            <Fact label="Responsibility">
                                <span className="inline-flex items-center gap-1.5"><span aria-hidden="true" className="size-2 rounded-full" style={{ background: responsibility?.color ?? 'var(--pm-accent)' }} />{responsibility?.name ?? 'Inbox'}</span>
                            </Fact>
                            {event.location && <Fact label="Place">{event.location}</Fact>}
                            {event.notes && <Fact label="Notes"><span className="whitespace-pre-line">{event.notes}</span></Fact>}
                        </dl>

                        <div className="pm-button-group mt-6">
                            <Button type="button" variant="danger" size="small" disabled={busy} onClick={() => setConfirming(true)}>Delete</Button>
                            <span className="flex-1" />
                            <Button type="button" size="small" disabled={busy} onClick={() => setMode('edit')}>Edit</Button>
                        </div>
                    </>
                )}
            </dialog>

            {confirming && (
                <ConfirmDialog
                    title={`Delete “${event.title}”?`}
                    description="This cannot be undone."
                    confirmLabel="Delete event"
                    busy={busy}
                    onConfirm={remove}
                    onCancel={() => setConfirming(false)}
                />
            )}
        </>
    );
}
