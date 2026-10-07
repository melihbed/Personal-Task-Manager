import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import {
    addedMessage,
    buildEditPayload,
    describeWhen,
    importAvailability,
    initialEditValues,
    type EditScope,
    type ImportType,
} from '../lib/google-events';
import type { GoogleEvent } from '../lib/planner';
import GoogleEventScopeDialog from './google-event-scope-dialog';
import Button from './ui/button';
import ConfirmDialog from './ui/confirm-dialog';
import Field from './ui/field';
import { useToast } from './ui/toast';

type Props = {
    event: GoogleEvent;
    responsibilities: { id: number; name: string }[];
    /** The calendar timezone, used to show and change the event's time. */
    timezone: string;
    onClose: () => void;
};

const actions: { type: ImportType; label: string; hint: string }[] = [
    { type: 'task', label: 'Add as task', hint: 'A task with this time as its deadline.' },
    { type: 'session', label: 'Add as work session', hint: 'Plans a session at this time.' },
    { type: 'routine', label: 'Make a routine', hint: 'Repeats the way this event does.' },
];

type Errors = Record<string, string>;
const firstError = (errors: Errors, fallback: string) => errors.event ?? errors.type ?? errors.title ?? errors.ends_at ?? errors.end_date ?? errors.responsibility_id ?? Object.values(errors)[0] ?? fallback;

/** Change an event's title and time. The change is saved to Google Calendar itself. */
function EditForm({ event, timezone, onCancel, onSaved }: { event: GoogleEvent; timezone: string; onCancel: () => void; onSaved: () => void }) {
    const repeats = event.recurring_event_id !== null;
    const [values, setValues] = useState(() => initialEditValues(event, timezone));
    const [scope, setScope] = useState<EditScope>('event');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const set = (field: keyof typeof values, value: string) => setValues(current => ({ ...current, [field]: value }));

    function submit(submitted: FormEvent<HTMLFormElement>) {
        submitted.preventDefault();
        setError('');

        let payload: Record<string, string | boolean>;

        try {
            payload = buildEditPayload(event, scope, values, timezone);
        } catch (problem) {
            setError((problem as Error).message);
            return;
        }

        setSaving(true);
        router.patch('/integrations/google/events', payload, {
            preserveScroll: true,
            onSuccess: onSaved,
            onError: (errors) => setError(firstError(errors, 'Could not save to Google Calendar. Please try again.')),
            onFinish: () => setSaving(false),
        });
    }

    return (
        <form onSubmit={submit} className="space-y-5">
            <h2 id="google-event-title" className="text-xl font-medium">Edit event</h2>
            <p className="-mt-2 text-xs text-[var(--pm-muted)]">Saved to Google Calendar itself, so it changes there too.</p>

            <Field id="google-event-name" label="Title" value={values.title} onChange={(change) => set('title', change.target.value)} maxLength={255} autoFocus required />

            {repeats && (
                <fieldset className="space-y-2" disabled={saving}>
                    <legend className="text-sm font-medium">Apply to</legend>
                    {([
                        { value: 'event', label: 'Only this event', hint: 'Its title and exact time.' },
                        { value: 'series', label: 'All events in the series', hint: event.all_day ? 'The title. The days stay the same.' : 'The title, time of day and length. The days stay the same.' },
                    ] as const).map(option => (
                        <label key={option.value} className="flex cursor-pointer items-start gap-3 text-sm">
                            <input type="radio" name="edit-scope" checked={scope === option.value} onChange={() => setScope(option.value)} className="mt-1 accent-[var(--pm-text)]" />
                            <span>{option.label}<span className="block text-xs text-[var(--pm-muted)]">{option.hint}</span></span>
                        </label>
                    ))}
                </fieldset>
            )}

            {event.all_day ? (
                scope === 'event' && (
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label htmlFor="google-event-first" className="text-sm font-medium">First day</label>
                            <input id="google-event-first" type="date" value={values.startDate} onChange={(change) => set('startDate', change.target.value)} className="pm-input" required />
                        </div>
                        <div>
                            <label htmlFor="google-event-last" className="text-sm font-medium">Last day</label>
                            <input id="google-event-last" type="date" min={values.startDate} value={values.endDate} onChange={(change) => set('endDate', change.target.value)} className="pm-input" required />
                        </div>
                    </div>
                )
            ) : (
                <div className="grid grid-cols-2 gap-4">
                    {scope === 'event' && (
                        <div>
                            <label htmlFor="google-event-start-date" className="text-sm font-medium">Starts on</label>
                            <input id="google-event-start-date" type="date" value={values.startDate} onChange={(change) => set('startDate', change.target.value)} className="pm-input" required />
                        </div>
                    )}
                    <div>
                        <label htmlFor="google-event-start-time" className="text-sm font-medium">Starts at</label>
                        <input id="google-event-start-time" type="time" value={values.startTime} onChange={(change) => set('startTime', change.target.value)} className="pm-input" required />
                    </div>
                    {scope === 'event' && (
                        <div>
                            <label htmlFor="google-event-end-date" className="text-sm font-medium">Ends on</label>
                            <input id="google-event-end-date" type="date" min={values.startDate} value={values.endDate} onChange={(change) => set('endDate', change.target.value)} className="pm-input" required />
                        </div>
                    )}
                    <div>
                        <label htmlFor="google-event-end-time" className="text-sm font-medium">Ends at</label>
                        <input id="google-event-end-time" type="time" value={values.endTime} onChange={(change) => set('endTime', change.target.value)} className="pm-input" required />
                    </div>
                </div>
            )}
            {!event.all_day && <p className="-mt-2 text-xs text-[var(--pm-muted)]">{timezone.replaceAll('_', ' ')}</p>}

            {error && <p role="alert" className="text-sm text-red-700">{error}</p>}

            <div className="pm-button-group justify-end">
                <Button type="button" variant="secondary" disabled={saving} onClick={onCancel}>Cancel</Button>
                <Button type="submit" loading={saving} loadingLabel="Saving to Google" disabled={values.title.trim() === ''}>Save to Google</Button>
            </div>
        </form>
    );
}

/**
 * An event from Google Calendar. It can be brought into the planner as a copy (task, work session or routine),
 * hidden from the planner, changed, or deleted in Google. Nothing is guessed: each is the user's choice, and for
 * a repeating event the user chooses between that event and the whole series.
 */
export default function GoogleEventDialog({ event, responsibilities, timezone, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const toast = useToast();
    const [mode, setMode] = useState<'view' | 'edit'>('view');
    const [prompt, setPrompt] = useState<'delete' | 'hide' | null>(null);
    const [responsibilityId, setResponsibilityId] = useState<number | null>(null);
    const [busy, setBusy] = useState<ImportType | 'delete' | 'hide' | null>(null);
    const [error, setError] = useState('');
    const availability = importAvailability(event);
    const repeats = event.recurring_event_id !== null;

    useEffect(() => { dialog.current?.showModal(); }, []);

    function add(type: ImportType) {
        setBusy(type);
        setError('');

        router.post('/integrations/google/imports', {
            calendar_id: event.calendar_id,
            event_id: event.event_id,
            type,
            responsibility_id: responsibilityId,
            timezone,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.show({ message: addedMessage(event.title, type) });
                onClose();
            },
            onError: (errors) => setError(firstError(errors, 'Could not add that event. Please try again.')),
            onFinish: () => setBusy(null),
        });
    }

    function hide(scope: EditScope) {
        setBusy('hide');
        setError('');

        router.post('/integrations/google/hidden', {
            calendar_id: event.calendar_id,
            event_id: event.event_id,
            scope,
            recurring_event_id: event.recurring_event_id,
            title: event.title,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.show({ message: `Hidden from your planner. Show it again in Integrations → Google Calendar.` });
                onClose();
            },
            onError: (errors) => setError(firstError(errors, 'Could not hide that event. Please try again.')),
            onFinish: () => setBusy(null),
        });
    }

    function remove(scope: EditScope) {
        setBusy('delete');
        setError('');

        router.delete('/integrations/google/events', {
            data: { calendar_id: event.calendar_id, event_id: event.event_id, scope },
            preserveScroll: true,
            onSuccess: () => {
                toast.show({ message: scope === 'series' ? 'Deleted the series from Google Calendar.' : 'Deleted from Google Calendar.' });
                onClose();
            },
            onError: (errors) => setError(firstError(errors, 'Could not delete that event. Please try again.')),
            onFinish: () => setBusy(null),
        });
    }

    const locked = busy !== null || prompt !== null;

    return (
        <>
            <dialog
                ref={dialog}
                aria-labelledby="google-event-title"
                className="pm-dialog"
                onCancel={(cancel) => { if (locked) cancel.preventDefault(); else onClose(); }}
                onClose={onClose}
            >
                {mode === 'edit' ? (
                    <EditForm
                        event={event}
                        timezone={timezone}
                        onCancel={() => setMode('view')}
                        onSaved={() => {
                            toast.show({ message: 'Saved to Google Calendar.' });
                            onClose();
                        }}
                    />
                ) : (
                    <>
                        <div className="flex items-start justify-between gap-4">
                            <h2 id="google-event-title" className="text-xl font-medium break-words">{event.title}</h2>
                            <button type="button" disabled={locked} onClick={onClose} aria-label="Close event" className="pm-button pm-button--secondary pm-button--icon">×</button>
                        </div>

                        <p className="mt-3 flex items-center gap-2 text-sm text-[var(--pm-muted)]">
                            <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: event.color ?? 'var(--pm-muted)' }} />
                            {event.calendar} · Google Calendar
                        </p>
                        <p className="mt-4 font-medium">{describeWhen(event, timezone)}</p>

                        <dl className="mt-3 space-y-1.5 text-sm">
                            {repeats && <div className="text-[var(--pm-muted)]">↻ Part of a repeating series</div>}
                            {event.location && <div><dt className="sr-only">Location</dt><dd>{event.location}</dd></div>}
                            {event.guests > 0 && <div className="text-[var(--pm-muted)]"><dt className="sr-only">Guests</dt><dd>{event.guests} {event.guests === 1 ? 'guest' : 'guests'}</dd></div>}
                            {event.description && <div><dt className="sr-only">Description</dt><dd className="line-clamp-6 whitespace-pre-line text-[var(--pm-muted)]">{event.description}</dd></div>}
                        </dl>

                        <div className="pm-button-group mt-5">
                            <Button type="button" variant="secondary" size="small" disabled={locked} onClick={() => setMode('edit')}>Edit</Button>
                            <Button type="button" variant="secondary" size="small" loading={busy === 'hide'} loadingLabel="Hiding" disabled={locked} onClick={() => (repeats ? setPrompt('hide') : hide('event'))}>Hide in planner</Button>
                            <Button type="button" variant="danger" size="small" disabled={locked} onClick={() => setPrompt('delete')}>Delete from Google</Button>
                        </div>

                        <div className="mt-6 border-t border-[var(--pm-border)] pt-5">
                            <h3 className="text-sm font-medium">Add to your planner</h3>
                            <p className="mt-1 text-xs text-[var(--pm-muted)]">Makes a copy that is yours to change. The Google event is hidden here once it is added, and stays untouched in Google.</p>

                            <label htmlFor="google-event-responsibility" className="mt-4 block text-sm font-medium">Responsibility</label>
                            <select
                                id="google-event-responsibility"
                                value={responsibilityId ?? ''}
                                onChange={(change) => setResponsibilityId(change.target.value === '' ? null : Number(change.target.value))}
                                disabled={locked}
                                className="pm-input cursor-pointer"
                            >
                                <option value="">Inbox</option>
                                {responsibilities.map(responsibility => <option key={responsibility.id} value={responsibility.id}>{responsibility.name}</option>)}
                            </select>

                            <ul className="mt-4 space-y-3">
                                {actions.map((action) => {
                                    const { available, reason } = availability[action.type];

                                    return (
                                        <li key={action.type} className="flex items-center justify-between gap-4">
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium">{action.label}</p>
                                                <p className="text-xs text-[var(--pm-muted)]">{available ? action.hint : reason}</p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                size="small"
                                                className="shrink-0"
                                                loading={busy === action.type}
                                                loadingLabel={`${action.label}…`}
                                                disabled={!available || locked}
                                                onClick={() => add(action.type)}
                                            >
                                                Add
                                            </Button>
                                        </li>
                                    );
                                })}
                            </ul>
                            {error && prompt === null && <p role="alert" className="mt-4 text-sm text-red-700">{error}</p>}
                        </div>

                        {event.html_link && (
                            <p className="mt-5 text-sm">
                                <a href={event.html_link} target="_blank" rel="noopener noreferrer" className="pm-link">Open in Google Calendar</a>
                            </p>
                        )}
                    </>
                )}
            </dialog>

            {prompt === 'delete' && !repeats && (
                <ConfirmDialog
                    title={`Delete “${event.title}” from Google Calendar?`}
                    description={<>The event is deleted in Google Calendar itself, so it disappears everywhere your calendar is. This cannot be undone from here.{error && <span role="alert" className="mt-2 block text-red-700">{error}</span>}</>}
                    confirmLabel="Delete from Google"
                    busy={busy === 'delete'}
                    onConfirm={() => remove('event')}
                    onCancel={() => { setPrompt(null); setError(''); }}
                />
            )}
            {prompt === 'delete' && repeats && (
                <GoogleEventScopeDialog
                    title={`Delete “${event.title}” from Google Calendar?`}
                    description="This changes your real Google Calendar and cannot be undone from here."
                    options={[
                        { value: 'event', label: 'Only this event', hint: 'The rest of the series stays.' },
                        { value: 'series', label: 'All events in the series', hint: 'Deletes the whole repeating series from Google Calendar.' },
                    ]}
                    confirmLabel="Delete from Google"
                    destructive
                    busy={busy === 'delete'}
                    error={error}
                    onConfirm={remove}
                    onCancel={() => { setPrompt(null); setError(''); }}
                />
            )}
            {prompt === 'hide' && (
                <GoogleEventScopeDialog
                    title={`Hide “${event.title}” from your planner?`}
                    description="Nothing changes in Google Calendar. You can show it again in Integrations → Google Calendar."
                    options={[
                        { value: 'event', label: 'Only this event', hint: 'The rest of the series stays visible.' },
                        { value: 'series', label: 'All events in the series', hint: 'Hides every event of this repeating series.' },
                    ]}
                    confirmLabel="Hide"
                    busy={busy === 'hide'}
                    error={error}
                    onConfirm={hide}
                    onCancel={() => { setPrompt(null); setError(''); }}
                />
            )}
        </>
    );
}
