import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { buildEditPayload, type EditValues } from '../lib/google-events';
import { dateLabel, localToISO, timeLabel, type GoogleEvent } from '../lib/planner';
import Button from './ui/button';
import { useToast } from './ui/toast';

type Props = {
    event: GoogleEvent;
    /** Where the event would go: the dropped day and, for a timed event, its new start and end. */
    values: EditValues;
    timezone: string;
    onClose: () => void;
};

const dayText = (date: string) => dateLabel(date, { weekday: 'long', month: 'short', day: 'numeric' });

/**
 * Asks before a dragged Google event is moved, because the move is saved to Google Calendar itself. A repeating event can move
 * only that one, or every event in the series to the same time of day.
 */
export default function GoogleEventMoveDialog({ event, values, timezone, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const toast = useToast();
    const [busy, setBusy] = useState<'event' | 'series' | null>(null);
    const [error, setError] = useState('');
    const repeats = event.recurring_event_id !== null;

    useEffect(() => { dialog.current?.showModal(); }, []);

    let when: string;
    let time = '';

    if (event.all_day) {
        when = values.endDate === values.startDate ? dayText(values.startDate) : `${dayText(values.startDate)} to ${dayText(values.endDate)}`;
    } else {
        const start = localToISO(values.startDate, values.startTime, timezone);
        const end = localToISO(values.endDate, values.endTime, timezone);

        time = `${timeLabel(start, timezone)} – ${timeLabel(end, timezone)}`;
        when = dayText(values.startDate);
    }

    function move(scope: 'event' | 'series') {
        let payload: Record<string, string | boolean>;

        try {
            payload = buildEditPayload(event, scope, values, timezone);
        } catch (problem) {
            setError((problem as Error).message);

            return;
        }

        setBusy(scope);
        setError('');

        router.patch('/integrations/google/events', payload, {
            preserveScroll: true,
            onSuccess: () => {
                toast.show({ message: scope === 'series' ? 'Moved the series in Google Calendar.' : 'Moved in Google Calendar.' });
                onClose();
            },
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Could not save to Google Calendar. Please try again.'),
            onFinish: () => setBusy(null),
        });
    }

    return (
        <dialog ref={dialog} aria-labelledby="move-google-title" className="pm-dialog" onCancel={(cancel) => { if (busy) cancel.preventDefault(); else onClose(); }} onClose={onClose}>
            <h2 id="move-google-title" className="text-xl font-medium break-words">Move “{event.title}”?</h2>
            <p className="mt-3 text-sm">{when}</p>
            {time && <p className="mt-1 font-medium">{time}</p>}
            <p className="mt-3 text-xs text-[var(--pm-muted)]">This is saved to Google Calendar itself, so it changes there too.</p>

            <div className="mt-6 space-y-3">
                <div>
                    <Button type="button" className="w-full" loading={busy === 'event'} loadingLabel="Moving this event" disabled={busy !== null} onClick={() => move('event')}>{repeats ? 'Only this event' : 'Move event'}</Button>
                    {repeats && <p className="mt-1 px-1 text-xs text-[var(--pm-muted)]">Every other event in the series stays as it is.</p>}
                </div>
                {repeats && !event.all_day && (
                    <div>
                        <Button type="button" variant="secondary" className="w-full" loading={busy === 'series'} loadingLabel="Moving the series" disabled={busy !== null} onClick={() => move('series')}>All events in the series</Button>
                        <p className="mt-1 px-1 text-xs text-[var(--pm-muted)]">Every event starts at {timeLabel(localToISO(values.startDate, values.startTime, timezone), timezone)} instead. The days stay the same.</p>
                    </div>
                )}
            </div>

            {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}

            <div className="pm-button-group mt-6 justify-end">
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={onClose}>Cancel</Button>
            </div>
        </dialog>
    );
}
