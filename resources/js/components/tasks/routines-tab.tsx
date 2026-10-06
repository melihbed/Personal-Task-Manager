import { router } from '@inertiajs/react';
import { useState } from 'react';
import { timeLabel, type PlannerRoutine, type RoutineOccurrence } from '../../lib/planner';
import { routineSummary } from '../../lib/routines';
import ConfirmDialog from '../ui/confirm-dialog';
import Menu from '../ui/menu';
import { useToast } from '../ui/toast';

type Props = {
    routines: PlannerRoutine[];
    today: RoutineOccurrence[];
    responsibilities: { id: number; name: string; color: string | null }[];
    timezone: string;
    onNew: () => void;
    onSelectOccurrence: (occurrence: RoutineOccurrence) => void;
    onEdit: (routine: PlannerRoutine) => void;
};

export default function RoutinesTab({ routines, today, responsibilities, timezone, onNew, onSelectOccurrence, onEdit }: Props) {
    const toast = useToast();
    const [pending, setPending] = useState<string | null>(null);
    const [deleting, setDeleting] = useState<PlannerRoutine | null>(null);
    const [busy, setBusy] = useState(false);

    function toggle(occurrence: RoutineOccurrence) {
        const key = `${occurrence.routine_id}-${occurrence.occurs_on}`;

        setPending(key);
        router.patch(`/routines/${occurrence.routine_id}/occurrences/${occurrence.occurs_on}`, { completed: !occurrence.completed }, {
            preserveScroll: true,
            onError: () => toast.show({ message: 'Could not update this routine. Please try again.' }),
            onFinish: () => setPending(null),
        });
    }

    function remove(routine: PlannerRoutine) {
        setBusy(true);
        router.delete(`/routines/${routine.id}`, {
            preserveScroll: true,
            onSuccess: () => setDeleting(null),
            onError: () => toast.show({ message: 'Could not delete that routine. Please try again.' }),
            onFinish: () => setBusy(false),
        });
    }

    if (routines.length === 0) {
        return (
            <div className="px-6 py-10 text-center">
                <p className="text-sm font-medium">No routines yet</p>
                <p className="mx-auto mt-2 max-w-60 text-xs text-[var(--pm-muted)]">Things that repeat on a schedule, like “Prepare breakfast, Tue and Thu at 8 AM”.</p>
                <button type="button" onClick={onNew} className="pm-button mt-4">＋ New routine</button>
            </div>
        );
    }

    return (
        <div className="px-4 py-4">
            <h3 className="text-xs font-semibold tracking-wide text-[var(--pm-muted)] uppercase">Today</h3>
            {today.length === 0 && <p className="mt-2 text-xs text-[var(--pm-muted)]">Nothing scheduled today.</p>}
            <ul className="mt-1">
                {today.map((occurrence) => {
                    const key = `${occurrence.routine_id}-${occurrence.occurs_on}`;

                    return (
                        <li key={key} className="flex items-center gap-3 rounded-xl px-1.5 py-2.5 transition hover:bg-[var(--pm-background)]/60">
                            <button
                                type="button"
                                disabled={pending !== null}
                                onClick={() => toggle(occurrence)}
                                aria-label={`${occurrence.completed ? 'Mark not done' : 'Mark done'}: ${occurrence.title}`}
                                aria-pressed={occurrence.completed}
                                className="pm-task-check"
                            >
                                {occurrence.completed && <span aria-hidden="true" className="text-xs">✓</span>}
                            </button>
                            <button type="button" onClick={() => onSelectOccurrence(occurrence)} className="min-w-0 flex-1 cursor-pointer text-left">
                                <span className={`block truncate text-sm ${occurrence.completed ? 'text-[var(--pm-muted)] line-through' : ''}`}>{occurrence.title}</span>
                                <span className="block text-[11px] text-[var(--pm-muted)]">{timeLabel(occurrence.starts_at, timezone)} – {timeLabel(occurrence.ends_at, timezone)}{occurrence.moved ? ' · moved' : ''}</span>
                            </button>
                        </li>
                    );
                })}
            </ul>

            <h3 className="mt-5 text-xs font-semibold tracking-wide text-[var(--pm-muted)] uppercase">All routines</h3>
            <ul className="mt-1">
                {routines.map((routine) => {
                    const responsibility = responsibilities.find(item => item.id === routine.responsibility_id);

                    return (
                        <li key={routine.id} className="flex items-start gap-3 rounded-xl px-1.5 py-2.5 transition hover:bg-[var(--pm-background)]/60">
                            <span aria-hidden="true" className="mt-1.5 size-2 shrink-0 rounded-full" style={{ background: responsibility?.color ?? 'var(--pm-accent)' }} />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">{routine.title}</p>
                                <p className="mt-0.5 text-[11px] text-[var(--pm-muted)]">{routineSummary(routine)}</p>
                                <p className="text-[11px] text-[var(--pm-muted)]">{responsibility?.name ?? 'Inbox'}</p>
                            </div>
                            <Menu
                                label={`Actions for ${routine.title}`}
                                items={[
                                    { label: 'Edit routine…', onSelect: () => onEdit(routine) },
                                    { label: 'Delete routine', onSelect: () => setDeleting(routine), danger: true },
                                ]}
                            />
                        </li>
                    );
                })}
            </ul>

            {deleting && (
                <ConfirmDialog
                    title={`Delete “${deleting.title}”?`}
                    description="Every event of this routine is removed from your calendar. This cannot be undone."
                    confirmLabel="Delete routine"
                    busy={busy}
                    onConfirm={() => remove(deleting)}
                    onCancel={() => setDeleting(null)}
                />
            )}
        </div>
    );
}
