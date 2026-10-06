import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { timeLabel, type PlannerRoutine, type RoutineOccurrence } from '../lib/planner';
import { routineSummary } from '../lib/routines';
import Button from './ui/button';

type Props = {
    occurrence: RoutineOccurrence;
    routine?: PlannerRoutine;
    timezone: string;
    onEditRoutine: () => void;
    onClose: () => void;
};

type Action = 'done' | 'skip' | 'reset';

export default function RoutineOccurrenceDialog({ occurrence, routine, timezone, onEditRoutine, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [busy, setBusy] = useState<Action | null>(null);
    const [error, setError] = useState('');

    useEffect(() => { dialog.current?.showModal(); }, []);

    function change(action: Action, payload: Record<string, boolean | null>) {
        setBusy(action);
        setError('');
        router.patch(`/routines/${occurrence.routine_id}/occurrences/${occurrence.occurs_on}`, payload, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => setError('Could not save that change. Please try again.'),
            onFinish: () => setBusy(null),
        });
    }

    return (
        <dialog
            ref={dialog}
            aria-labelledby="occurrence-title"
            className="pm-dialog"
            onCancel={(event) => { if (busy) event.preventDefault(); else onClose(); }}
            onClose={onClose}
        >
            <div className="flex items-start justify-between gap-4">
                <h2 id="occurrence-title" className="text-xl font-medium break-words">{occurrence.title}</h2>
                <button type="button" disabled={busy !== null} onClick={onClose} aria-label="Close routine" className="pm-button pm-button--secondary pm-button--icon">×</button>
            </div>
            <p className="mt-3 text-sm text-[var(--pm-muted)]">{occurrence.responsibility_name}{occurrence.completed ? ' · Done' : ''}</p>
            <p className="mt-5 text-sm">{new Intl.DateTimeFormat(undefined, { timeZone: timezone, weekday: 'long', month: 'short', day: 'numeric' }).format(new Date(occurrence.starts_at))}</p>
            <p className="mt-1 font-medium">{timeLabel(occurrence.starts_at, timezone)} – {timeLabel(occurrence.ends_at, timezone)}</p>
            {routine && <p className="mt-2 text-xs text-[var(--pm-muted)]">↻ {routineSummary(routine)}</p>}

            {occurrence.moved && (
                <div className="mt-4 flex items-center justify-between gap-3 rounded-xl bg-[var(--pm-background)] px-3 py-2 text-sm">
                    <span>Moved from its usual time.</span>
                    <Button type="button" variant="secondary" size="small" loading={busy === 'reset'} loadingLabel="Resetting time" disabled={busy !== null} onClick={() => change('reset', { starts_at: null, ends_at: null })}>
                        Reset
                    </Button>
                </div>
            )}

            {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}

            <div className="pm-button-group mt-6">
                <Button type="button" loading={busy === 'done'} loadingLabel="Saving" disabled={busy !== null} onClick={() => change('done', { completed: !occurrence.completed })}>
                    {occurrence.completed ? 'Mark not done' : 'Mark done'}
                </Button>
                <Button type="button" variant="secondary" loading={busy === 'skip'} loadingLabel="Skipping" disabled={busy !== null} onClick={() => change('skip', { skipped: true })}>
                    Skip this one
                </Button>
                <Button type="button" variant="secondary" disabled={busy !== null} onClick={onEditRoutine}>Edit routine</Button>
            </div>
            <p className="mt-4 text-xs text-[var(--pm-muted)]">Skipping or moving affects only this day. Edit routine changes every day.</p>
        </dialog>
    );
}
