import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import AddTaskForm from '../../components/add-task-form';
import DuePill from '../../components/due-pill';
import ConfirmDialog from '../../components/ui/confirm-dialog';
import Menu from '../../components/ui/menu';
import AppLayout from '../../layouts/app-layout';
import { useUndoableDelete } from '../../lib/use-undoable-delete';

type Task = {
    id: number;
    title: string;
    priority: string;
    due_at: string | null;
    due_has_time: boolean;
    completed_at: string | null;
    calendar_sessions_count: number;
};
type Props = {
    responsibility: { id: number; name: string; description: string | null };
    tasks: Task[];
};

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

function ShowContent({ responsibility, tasks: allTasks }: Props) {
    const undoable = useUndoableDelete(useCallback((id: number) => `/tasks/${id}`, []), 'Task deleted');
    const [confirming, setConfirming] = useState<Task | null>(null);
    const [deleting, setDeleting] = useState(false);
    const tasks = allTasks.filter(task => !undoable.pending.has(task.id));
    const [pending, setPending] = useState<number[]>([]);
    const [updateError, setUpdateError] = useState('');
    const openTasks = tasks.filter(task => !task.completed_at);
    const completedTasks = tasks.filter(task => task.completed_at);
    const percentage = tasks.length ? Math.round(completedTasks.length / tasks.length * 100) : 0;

    function toggleTask(task: Task) {
        setUpdateError('');
        setPending(current => [...current, task.id]);
        router.patch(`/tasks/${task.id}/completion`, { completed: !task.completed_at }, {
            preserveScroll: true,
            onError: () => setUpdateError('Could not update this task. Please try again.'),
            onFinish: () => setPending(current => current.filter(id => id !== task.id)),
        });
    }

    function requestDelete(task: Task) {
        if (task.calendar_sessions_count > 0) setConfirming(task);
        else undoable.schedule(task.id);
    }

    function deleteNow(task: Task) {
        setDeleting(true);
        router.delete(`/tasks/${task.id}`, {
            preserveScroll: true,
            onSuccess: () => setConfirming(null),
            onFinish: () => setDeleting(false),
        });
    }

    function taskRow(task: Task) {
        const complete = !!task.completed_at;
        const due = task.due_at ? new Date(task.due_at) : null;
        return (
            <li key={task.id} className="flex items-start gap-4 border-t border-[var(--pm-border)] py-4 first:border-t-0">
                <input type="checkbox" checked={complete} disabled={pending.includes(task.id)} onChange={() => toggleTask(task)} aria-label={`${complete ? 'Reopen' : 'Complete'} task: ${task.title}`} className="mt-1 h-5 w-5 shrink-0 cursor-pointer accent-[var(--pm-text)] disabled:cursor-wait disabled:opacity-50" />
                <div className="min-w-0 flex-1">
                    <span className={`block text-sm leading-6 break-words ${complete ? 'text-[var(--pm-muted)] line-through' : 'font-medium'}`}>{task.title}</span>
                    {(due || task.priority !== 'normal') && (
                        <div className="mt-2 flex flex-wrap gap-2 text-xs">
                            {due && <DuePill task={task} timezone={timezone} />}
                            {task.priority !== 'normal' && <span className="rounded-full bg-[var(--pm-background)] px-2 py-0.5">{task.priority === 'high' ? 'High priority' : 'Low priority'}</span>}
                        </div>
                    )}
                </div>
                <Menu
                    label={`Actions for ${task.title}`}
                    items={[
                        { label: complete ? 'Reopen' : 'Mark done', onSelect: () => toggleTask(task) },
                        { label: 'Delete task', onSelect: () => requestDelete(task), danger: true },
                    ]}
                />
            </li>
        );
    }

    return (
        <>
            <div className="mx-auto max-w-4xl">
                <div className="mb-7 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-3xl font-medium tracking-tight">What needs your attention?</h2>
                        <p className="mt-2 text-sm text-[var(--pm-muted)]">{openTasks.length ? `${openTasks.length} unfinished ${openTasks.length === 1 ? 'task' : 'tasks'}. Choose one clear next step.` : tasks.length ? 'Everything on this list is complete.' : 'Capture your first task to get started.'}</p>
                    </div>
                    {tasks.length > 0 && (
                        <div className="min-w-40 pt-1" aria-label={`${completedTasks.length} of ${tasks.length} tasks complete`}>
                            <div className="mb-2 flex justify-between gap-6 text-xs text-[var(--pm-muted)]"><span>{completedTasks.length} / {tasks.length} done</span><span>{percentage}%</span></div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-[var(--pm-border)]"><div className="h-full rounded-full bg-[var(--pm-accent)]" style={{ width: `${percentage}%` }} /></div>
                        </div>
                    )}
                </div>
                <section className="pm-card" aria-labelledby="open-tasks-heading">
                    <div className="mb-6"><AddTaskForm responsibilityId={responsibility.id} /></div>
                    <div className="mb-2 flex items-center justify-between"><h3 id="open-tasks-heading" className="text-lg font-medium">To do</h3><span className="rounded-full bg-[var(--pm-background)] px-3 py-1 text-xs">{openTasks.length}</span></div>
                    {updateError && <p role="alert" className="my-3 text-sm text-red-700">{updateError}</p>}
                    {openTasks.length ? <ul>{openTasks.map(taskRow)}</ul> : <div className="py-10 text-center"><p className="text-sm font-medium">{tasks.length ? 'You’re all caught up.' : 'A clear list starts with one task.'}</p><p className="mt-2 text-sm text-[var(--pm-muted)]">{tasks.length ? 'Add another task when you’re ready.' : 'Try “Write the capstone introduction”.'}</p></div>}
                </section>
                {completedTasks.length > 0 && (
                    <details className="pm-card mt-5">
                        <summary className="cursor-pointer text-sm font-medium">Completed ({completedTasks.length})</summary>
                        <ul className="mt-4">{completedTasks.map(taskRow)}</ul>
                    </details>
                )}
            </div>
            {confirming && (
                <ConfirmDialog
                    title={`Delete “${confirming.title}”?`}
                    description={`This also removes its ${confirming.calendar_sessions_count} calendar ${confirming.calendar_sessions_count === 1 ? 'session' : 'sessions'} from your calendar. This cannot be undone.`}
                    confirmLabel="Delete task"
                    busy={deleting}
                    onConfirm={() => deleteNow(confirming)}
                    onCancel={() => setConfirming(null)}
                />
            )}
        </>
    );
}

export default function Show(props: Props) {
    return (
        <AppLayout title={props.responsibility.name} backHref="/">
            <ShowContent {...props} />
        </AppLayout>
    );
}
