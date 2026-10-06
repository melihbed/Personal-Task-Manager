import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import type { PlannerTask } from '../lib/planner';

type Props = { id: number | null; name: string; color: string | null; tasks: PlannerTask[]; timezone: string; onSchedule: (task: PlannerTask) => void };
export default function TaskGroup({ id, name, color, tasks, timezone, onSchedule }: Props) {
    const [open, setOpen] = useState(true);
    const [pending, setPending] = useState<number | null>(null);
    const [error, setError] = useState('');
    const form = useForm({ title: '' });
    const unfinished = tasks.filter(task => !task.completed_at);
    const completed = tasks.filter(task => !!task.completed_at);
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(id === null ? '/tasks' : `/responsibilities/${id}/tasks`, { preserveScroll: true, onSuccess: () => form.reset() });
    }
    function complete(task: PlannerTask) {
        setPending(task.id); setError('');
        router.patch(`/tasks/${task.id}/completion`, { completed: !task.completed_at }, { preserveScroll: true, onError: () => setError('Could not complete this task. Please try again.'), onFinish: () => setPending(null) });
    }
    return <section className="border-b border-[var(--pm-border)] last:border-b-0">
        <div className="flex items-center gap-2 px-4 py-4">
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} aria-controls={`group-${id ?? 'inbox'}`} className="pm-disclosure min-w-0 flex-1">
                <svg aria-hidden="true" className={`h-4 w-4 shrink-0 transition-transform ${open ? 'rotate-90' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="m9 5 7 7-7 7" /></svg>
                <span aria-hidden="true" className="h-2 w-2 shrink-0 rounded-full" style={{ background: color ?? 'var(--pm-accent)' }} />
                <span className="truncate text-sm font-semibold">{name}</span><span className="ml-auto text-xs text-[var(--pm-muted)]">{unfinished.length}</span>
            </button>
            {id !== null && <Link href={`/responsibilities/${id}`} aria-label={`Open ${name}`} title={`Open ${name}`} className="pm-button pm-button--secondary pm-button--small pm-button--icon"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M7 17 17 7M7 7h10v10" /></svg></Link>}
        </div>
        {open && <div id={`group-${id ?? 'inbox'}`} className="px-4 pb-4">
            {unfinished.length === 0 && <p className="pb-3 text-xs text-[var(--pm-muted)]">No unfinished tasks.</p>}
            <ul className="space-y-1">{unfinished.map(task => <li key={task.id} className="flex items-start gap-3 rounded-xl py-3">
                <button type="button" disabled={pending !== null} onClick={() => complete(task)} aria-label={`Complete ${task.title}`} className="pm-task-check mt-1">{pending === task.id && <span aria-hidden="true">·</span>}</button>
                <div className="min-w-0 flex-1"><p className="text-sm leading-5 break-words">{task.title}</p><div className="mt-1 flex flex-wrap gap-x-2 text-[11px] text-[var(--pm-muted)]">
                    {task.due_at && <span className={Date.parse(task.due_at) < Date.now() ? 'text-red-700' : ''}>Due {new Intl.DateTimeFormat(undefined, { timeZone: timezone, month: 'short', day: 'numeric' }).format(new Date(task.due_at))}</span>}
                    {task.calendar_sessions_count > 0 && <span>{task.calendar_sessions_count} session{task.calendar_sessions_count === 1 ? '' : 's'}</span>}
                </div></div>
                <button type="button" onClick={() => onSchedule(task)} className="pm-button pm-button--secondary pm-button--small shrink-0" aria-label={`Schedule ${task.title}`}>Plan</button>
            </li>)}</ul>
            {completed.length > 0 && <details className="mt-3 text-xs text-[var(--pm-muted)]"><summary className="cursor-pointer">Completed ({completed.length})</summary><ul className="mt-2 space-y-2">{completed.map(task => <li key={task.id} className="flex items-center gap-3"><span className="min-w-0 flex-1 truncate line-through">{task.title}</span><button type="button" disabled={pending !== null} onClick={() => complete(task)} aria-label={`Reopen ${task.title}`} className="pm-button pm-button--secondary pm-button--small">Reopen</button></li>)}</ul></details>}
            {error && <p role="alert" className="mt-2 text-xs text-red-700">{error}</p>}
            <form onSubmit={submit} className="mt-2 flex gap-2"><input aria-label={`New task in ${name}`} placeholder="Add a task…" value={form.data.title} onChange={e => form.setData('title', e.target.value)} required maxLength={255} className="min-w-0 flex-1 rounded-lg border border-[var(--pm-border)] px-3 py-2 text-sm" /><button type="submit" disabled={form.processing} aria-label={`Add task to ${name}`} className="pm-button pm-button--icon">+</button></form>
            {form.errors.title && <p role="alert" className="mt-2 text-xs text-red-700">{form.errors.title}</p>}
        </div>}
    </section>;
}
