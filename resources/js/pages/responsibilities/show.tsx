import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../../../../../../Desktop/PersonalTaskManager-ConsistentButtons/resources/js/layouts/app-layout';

type Task = {
    id: number;
    title: string;
    priority: string;
    due_at: string | null;
    completed_at: string | null;
};
type Props = {
    responsibility: { id: number; name: string; description: string | null };
    tasks: Task[];
};

function ArrowIcon() {
    return <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5m-6 6 6-6 6 6" /></svg>;
}

export default function Show({ responsibility, tasks }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({ title: '' });
    const [pending, setPending] = useState<number[]>([]);
    const [updateError, setUpdateError] = useState('');
    const openTasks = tasks.filter(task => !task.completed_at);
    const completedTasks = tasks.filter(task => task.completed_at);
    const percentage = tasks.length ? Math.round(completedTasks.length / tasks.length * 100) : 0;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!data.title.trim()) return;
        post(`/responsibilities/${responsibility.id}/tasks`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    function toggleTask(task: Task) {
        setUpdateError('');
        setPending(current => [...current, task.id]);
        router.patch(`/tasks/${task.id}/completion`, { completed: !task.completed_at }, {
            preserveScroll: true,
            onError: () => setUpdateError('Could not update this task. Please try again.'),
            onFinish: () => setPending(current => current.filter(id => id !== task.id)),
        });
    }

    function taskRow(task: Task) {
        const complete = !!task.completed_at;
        const due = task.due_at ? new Date(task.due_at) : null;
        const overdue = due && !complete && due.getTime() < Date.now();
        return (
            <li key={task.id} className="flex items-start gap-4 border-t border-[var(--pm-border)] py-4 first:border-t-0">
                <input type="checkbox" checked={complete} disabled={pending.includes(task.id)} onChange={() => toggleTask(task)} aria-label={`${complete ? 'Reopen' : 'Complete'} task: ${task.title}`} className="mt-1 h-5 w-5 shrink-0 cursor-pointer accent-[var(--pm-text)] disabled:cursor-wait disabled:opacity-50" />
                <div className="min-w-0 flex-1">
                    <span className={`block text-sm leading-6 break-words ${complete ? 'text-[var(--pm-muted)] line-through' : 'font-medium'}`}>{task.title}</span>
                    {(due || task.priority !== 'normal') && (
                        <div className="mt-2 flex flex-wrap gap-2 text-xs">
                            {due && <span className={overdue ? 'text-red-700' : 'text-[var(--pm-muted)]'}>{overdue ? 'Overdue · ' : 'Due · '}{due.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}</span>}
                            {task.priority !== 'normal' && <span className="rounded-full bg-[var(--pm-background)] px-2 py-0.5">{task.priority === 'high' ? 'High priority' : 'Low priority'}</span>}
                        </div>
                    )}
                </div>
            </li>
        );
    }

    return (
        <AppLayout title={responsibility.name} backHref="/">
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
                    <form onSubmit={submit} className="mb-6">
                        <label htmlFor="task-title" className="mb-2 block text-sm font-medium">Add a task</label>
                        <div className="flex items-center gap-3 rounded-2xl border border-[var(--pm-border)] bg-[var(--pm-background)] p-2 focus-within:border-[var(--pm-text)]">
                            <input id="task-title" value={data.title} onChange={event => setData('title', event.target.value)} placeholder="What do you need to do?" required maxLength={255} aria-invalid={!!errors.title} aria-describedby={errors.title ? 'task-title-error' : 'task-entry-hint'} className="min-w-0 flex-1 bg-transparent px-3 py-2 text-sm outline-none" />
                            <button type="submit" disabled={processing || !data.title.trim()} aria-label={processing ? 'Saving task' : 'Add task'} title="Add task" className="pm-button pm-button--icon"><ArrowIcon /></button>
                        </div>
                        {errors.title ? <p id="task-title-error" className="mt-2 text-sm text-red-700" role="alert">{errors.title}</p> : <p id="task-entry-hint" className="mt-2 text-xs text-[var(--pm-muted)]">Press Enter to add. Keep it small and actionable.</p>}
                    </form>
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
        </AppLayout>
    );
}
