import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { timeLabel, zonedParts, type PlannerRoutine, type RoutineOccurrence } from '../lib/planner';
import { routineSummary, weekdays } from '../lib/routines';
import Button from './ui/button';

type Props = {
    occurrence: RoutineOccurrence;
    routine: PlannerRoutine;
    /** Where the occurrence was dropped, as UTC instants. */
    startsAt: string;
    endsAt: string;
    /** The calendar timezone, used to show the drop. */
    timezone: string;
    onClose: () => void;
};

const isoWeekday = (date: string) => new Date(`${date}T12:00:00Z`).getUTCDay() || 7;

/**
 * Asks what a dragged routine occurrence should change: only that day, or the whole routine.
 * "All events" keeps the routine's other days, moving this weekday's slot to the dropped weekday and time.
 */
export default function RoutineMoveDialog({ occurrence, routine, startsAt, endsAt, timezone, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [busy, setBusy] = useState<'one' | 'all' | null>(null);
    const [error, setError] = useState('');

    useEffect(() => { dialog.current?.showModal(); }, []);

    // The routine's own wall clock decides its weekday and time, which can differ from the calendar timezone.
    const dropped = zonedParts(new Date(startsAt), routine.timezone);
    const fromWeekday = isoWeekday(occurrence.occurs_on);
    const toWeekday = isoWeekday(dropped.date);
    const days = [...new Set(routine.days.map((day) => (day === fromWeekday ? toWeekday : day)))].sort((a, b) => a - b);
    const collides = toWeekday !== fromWeekday && routine.days.includes(toWeekday);
    const allEvents = { ...routine, days, start_time: dropped.time };

    const day = new Intl.DateTimeFormat(undefined, { timeZone: timezone, weekday: 'long', month: 'short', day: 'numeric' }).format(new Date(startsAt));

    function finish(scope: 'one' | 'all') {
        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => setError('Could not move it. Please try again.'),
            onFinish: () => setBusy(null),
        };

        setBusy(scope);
        setError('');

        if (scope === 'one') {
            router.patch(`/routines/${occurrence.routine_id}/occurrences/${occurrence.occurs_on}`, { starts_at: startsAt, ends_at: endsAt }, options);
            return;
        }

        router.patch(`/routines/${routine.id}`, {
            title: routine.title,
            responsibility_id: routine.responsibility_id,
            days,
            start_time: dropped.time,
            duration_minutes: routine.duration_minutes,
            timezone: routine.timezone,
            starts_on: routine.starts_on,
            ends_on: routine.ends_on,
        }, options);
    }

    return (
        <dialog
            ref={dialog}
            aria-labelledby="move-routine-title"
            className="pm-dialog"
            onCancel={(event) => { if (busy) event.preventDefault(); else onClose(); }}
            onClose={onClose}
        >
            <h2 id="move-routine-title" className="text-xl font-medium break-words">Move “{occurrence.title}”?</h2>
            <p className="mt-3 text-sm">{day}</p>
            <p className="mt-1 font-medium">{timeLabel(startsAt, timezone)} – {timeLabel(endsAt, timezone)}</p>

            <div className="mt-6 space-y-3">
                <div>
                    <Button type="button" className="w-full" loading={busy === 'one'} loadingLabel="Moving this event" disabled={busy !== null} onClick={() => finish('one')}>
                        Only this event
                    </Button>
                    <p className="mt-1 px-1 text-xs text-[var(--pm-muted)]">Every other day stays as it is.</p>
                </div>
                <div>
                    <Button type="button" variant="secondary" className="w-full" loading={busy === 'all'} loadingLabel="Moving all events" disabled={busy !== null || collides} onClick={() => finish('all')}>
                        All events
                    </Button>
                    <p className="mt-1 px-1 text-xs text-[var(--pm-muted)]">
                        {collides
                            ? `${weekdays[toWeekday - 1].name} is already part of this routine, so all events can't move there.`
                            : routineSummary(allEvents)}
                    </p>
                </div>
            </div>

            {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}

            <div className="pm-button-group mt-6 justify-end">
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={onClose}>Cancel</Button>
            </div>
        </dialog>
    );
}
