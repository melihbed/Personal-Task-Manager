import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { addedMessage, describeWhen, importAvailability, type ImportType, type Scope } from '../lib/google-events';
import type { GoogleEvent } from '../lib/planner';
import GoogleEventScopeDialog from './google-event-scope-dialog';
import Button from './ui/button';
import { useToast } from './ui/toast';

type Props = {
    event: GoogleEvent;
    responsibilities: { id: number; name: string }[];
    /** The calendar timezone, used to show the event's time. */
    timezone: string;
    onClose: () => void;
};

const actions: { type: ImportType; label: string; hint: string }[] = [
    { type: 'event', label: 'Add as event', hint: 'Keeps its time, place and notes.' },
    { type: 'task', label: 'Add as task', hint: 'A task with this time as its deadline.' },
    { type: 'session', label: 'Add as work session', hint: 'Plans a session at this time.' },
    { type: 'routine', label: 'Make a routine', hint: 'Repeats the way this event does.' },
];

type Errors = Record<string, string>;
const firstError = (errors: Errors, fallback: string) => errors.event ?? errors.type ?? Object.values(errors)[0] ?? fallback;

/**
 * An event previewed from Google Calendar. It is read-only: nothing here changes Google. It can be brought into the planner as
 * an event, task, work session or routine, which is then the user's own to change, or hidden from the preview. For a repeating
 * event the user chooses between that event and the whole series when hiding.
 */
export default function GoogleEventDialog({ event, responsibilities, timezone, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const toast = useToast();
    const [hiding, setHiding] = useState(false);
    const [responsibilityId, setResponsibilityId] = useState<number | null>(null);
    const [busy, setBusy] = useState<ImportType | 'hide' | null>(null);
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

    function hide(scope: Scope) {
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
                toast.show({ message: 'Hidden from the preview. Show it again in Integrations → Google Calendar.' });
                onClose();
            },
            onError: (errors) => setError(firstError(errors, 'Could not hide that event. Please try again.')),
            onFinish: () => setBusy(null),
        });
    }

    const locked = busy !== null || hiding;

    return (
        <>
            <dialog
                ref={dialog}
                aria-labelledby="google-event-title"
                className="pm-dialog"
                onCancel={(cancel) => { if (locked) cancel.preventDefault(); else onClose(); }}
                onClose={onClose}
            >
                <div className="flex items-start justify-between gap-4">
                    <h2 id="google-event-title" className="text-xl font-medium break-words">{event.title}</h2>
                    <button type="button" disabled={locked} onClick={onClose} aria-label="Close event" className="pm-button pm-button--secondary pm-button--icon">×</button>
                </div>

                <p className="mt-3 flex items-center gap-2 text-sm text-[var(--pm-muted)]">
                    <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: event.color ?? 'var(--pm-muted)' }} />
                    {event.calendar}, previewed from Google Calendar
                </p>
                <p className="mt-4 font-medium">{describeWhen(event, timezone)}</p>

                <dl className="mt-3 space-y-1.5 text-sm">
                    {repeats && <div className="text-[var(--pm-muted)]">↻ Part of a repeating series</div>}
                    {event.location && <div><dt className="sr-only">Location</dt><dd>{event.location}</dd></div>}
                    {event.guests > 0 && <div className="text-[var(--pm-muted)]"><dt className="sr-only">Guests</dt><dd>{event.guests} {event.guests === 1 ? 'guest' : 'guests'}</dd></div>}
                    {event.description && <div><dt className="sr-only">Description</dt><dd className="line-clamp-6 whitespace-pre-line text-[var(--pm-muted)]">{event.description}</dd></div>}
                </dl>

                <div className="mt-6 border-t border-[var(--pm-border)] pt-5">
                    <h3 className="text-sm font-medium">Make it yours</h3>
                    <p className="mt-1 text-xs text-[var(--pm-muted)]">This is a preview. Adding it makes a copy in your planner that you can change, plan and delete. Google is never changed, and the preview hides it once it is added.</p>

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
                                        variant={action.type === 'event' ? 'primary' : 'secondary'}
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
                    {error && !hiding && <p role="alert" className="mt-4 text-sm text-red-700">{error}</p>}
                </div>

                <div className="pm-button-group mt-6 justify-end">
                    <Button type="button" variant="secondary" size="small" loading={busy === 'hide'} loadingLabel="Hiding" disabled={locked} onClick={() => (repeats ? setHiding(true) : hide('event'))}>Hide from the preview</Button>
                </div>
            </dialog>

            {hiding && (
                <GoogleEventScopeDialog
                    title={`Hide “${event.title}” from the preview?`}
                    description="Nothing changes in Google Calendar. You can show it again in Integrations → Google Calendar."
                    options={[
                        { value: 'event', label: 'Only this event', hint: 'The rest of the series stays visible.' },
                        { value: 'series', label: 'All events in the series', hint: 'Hides every event of this repeating series.' },
                    ]}
                    confirmLabel="Hide"
                    busy={busy === 'hide'}
                    error={error}
                    onConfirm={hide}
                    onCancel={() => { setHiding(false); setError(''); }}
                />
            )}
        </>
    );
}
