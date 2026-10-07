import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { courseTitle } from '../lib/school';
import { formatLength } from '../lib/routines';
import type { PlannerTask } from '../lib/planner';
import { useCompanion } from './companion/companion-context';
import DuePill from './due-pill';
import EditTaskDialog from './edit-task-dialog';
import Button from './ui/button';
import ConfirmDialog from './ui/confirm-dialog';
import { useToast } from './ui/toast';

type Props = {
    task: PlannerTask;
    responsibilities: { id: number; name: string; color: string | null }[];
    timezone: string;
    /** Opens the form that reserves calendar time for the task. */
    onPlan: (task: PlannerTask) => void;
    onClose: () => void;
};

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
            <dt className="shrink-0 text-sm text-[var(--pm-muted)]">{label}</dt>
            <dd className="min-w-0 text-right text-sm break-words">{children}</dd>
        </div>
    );
}

/** A task opened from its deadline on the calendar: everything about it, with the ways to change, plan, finish or delete it. */
export default function TaskDetailsDialog({ task, responsibilities, timezone, onPlan, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const toast = useToast();
    const { celebrate } = useCompanion();
    const [mode, setMode] = useState<'view' | 'edit'>('view');
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState<'done' | 'delete' | null>(null);
    const responsibility = responsibilities.find(item => item.id === task.responsibility_id) ?? null;
    const done = task.completed_at !== null;
    const sessions = task.calendar_sessions_count;

    useEffect(() => {
        if (mode === 'view') dialog.current?.showModal();
    }, [mode]);

    if (mode === 'edit') return <EditTaskDialog task={task} responsibilities={responsibilities} timezone={timezone} onClose={onClose} />;

    function toggleDone() {
        setBusy('done');
        router.patch(`/tasks/${task.id}/completion`, { completed: !done }, {
            preserveScroll: true,
            onSuccess: () => {
                if (!done) celebrate();
                onClose();
            },
            onError: () => toast.show({ message: 'Could not update that task. Please try again.' }),
            onFinish: () => setBusy(null),
        });
    }

    function remove() {
        setBusy('delete');
        router.delete(`/tasks/${task.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.show({ message: `Deleted “${task.title}”.` });
                onClose();
            },
            onError: () => toast.show({ message: 'Could not delete that task. Please try again.' }),
            onFinish: () => { setBusy(null); setConfirming(false); },
        });
    }

    return (
        <>
            <dialog ref={dialog} aria-labelledby="task-details-title" className="pm-dialog" onCancel={(event) => { if (busy) event.preventDefault(); else onClose(); }} onClose={onClose}>
                <div className="flex items-start justify-between gap-4">
                    <h2 id="task-details-title" className={`text-xl font-medium break-words ${done ? 'text-[var(--pm-muted)] line-through' : ''}`}>{task.title}</h2>
                    <button type="button" disabled={busy !== null} onClick={onClose} aria-label="Close task" className="pm-button pm-button--secondary pm-button--icon">×</button>
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                    {task.due_at ? <DuePill task={task} timezone={timezone} /> : <span className="text-sm text-[var(--pm-muted)]">No deadline</span>}
                    {done && <span className="pm-badge pm-badge--ok">Done</span>}
                </div>

                <dl className="mt-5 divide-y divide-[var(--pm-border)]">
                    <Fact label="Responsibility">
                        <span className="inline-flex items-center gap-1.5"><span aria-hidden="true" className="size-2 rounded-full" style={{ background: responsibility?.color ?? 'var(--pm-accent)' }} />{responsibility?.name ?? 'Inbox'}</span>
                    </Fact>
                    {task.estimate_minutes !== null && <Fact label="Estimate">{formatLength(task.estimate_minutes)}</Fact>}
                    <Fact label="Planned time">{sessions === 0 ? 'None yet' : `${sessions} ${sessions === 1 ? 'session' : 'sessions'}`}</Fact>
                    {(task.focus_rounds_count ?? 0) > 0 && <Fact label="Focus rounds">{task.focus_rounds_count}</Fact>}
                    {task.canvas_assignment && <Fact label="From Canvas">{courseTitle(task.canvas_assignment.course.name)}</Fact>}
                    {task.notes && <Fact label="Notes"><span className="whitespace-pre-line">{task.notes}</span></Fact>}
                </dl>

                {task.canvas_assignment && <p className="mt-3 text-xs text-[var(--pm-muted)]">Canvas decides this task’s title and deadline, so changes to them are replaced at the next sync.</p>}

                <div className="pm-button-group mt-6">
                    <Button type="button" variant="danger" size="small" disabled={busy !== null} onClick={() => setConfirming(true)}>Delete</Button>
                    <span className="flex-1" />
                    <Button type="button" variant="secondary" size="small" disabled={busy !== null} onClick={() => setMode('edit')}>Edit</Button>
                    {!done && <Button type="button" variant="secondary" size="small" disabled={busy !== null} onClick={() => { onPlan(task); onClose(); }}>Plan time</Button>}
                    <Button type="button" size="small" loading={busy === 'done'} loadingLabel="Saving" disabled={busy !== null} onClick={toggleDone}>{done ? 'Reopen' : 'Mark done'}</Button>
                </div>
            </dialog>

            {confirming && (
                <ConfirmDialog
                    title={`Delete “${task.title}”?`}
                    description={sessions > 0 ? `This also removes its ${sessions} planned ${sessions === 1 ? 'session' : 'sessions'} from your calendar. This cannot be undone.` : 'This cannot be undone.'}
                    confirmLabel="Delete task"
                    busy={busy === 'delete'}
                    onConfirm={remove}
                    onCancel={() => setConfirming(false)}
                />
            )}
        </>
    );
}
