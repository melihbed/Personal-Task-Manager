import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { dueState } from '../../lib/deadlines';
import type { PlannerRoutine, PlannerTask, RoutineOccurrence } from '../../lib/planner';
import { buildSections, completedTasks, filterTasks, taskViews, type ResponsibilityFilter, type TaskView } from '../../lib/task-views';
import { useStoredState } from '../../lib/use-stored-state';
import { useUndoableDelete } from '../../lib/use-undoable-delete';
import AddTaskForm from '../add-task-form';
import { useCompanion } from '../companion/companion-context';
import EditTaskDialog from '../edit-task-dialog';
import ConfirmDialog from '../ui/confirm-dialog';
import Menu from '../ui/menu';
import { useToast } from '../ui/toast';
import RoutinesTab from './routines-tab';
import TaskRow from './task-row';

type Responsibility = { id: number; name: string; color: string | null };
type Tab = 'tasks' | 'routines';

type Props = {
    tasks: PlannerTask[];
    responsibilities: Responsibility[];
    routines: PlannerRoutine[];
    routinesToday: RoutineOccurrence[];
    timezone: string;
    draggingTaskId: number | null;
    onSchedule: (task: PlannerTask) => void;
    onDragTask: (task: PlannerTask | null) => void;
    onNewRoutine: () => void;
    onNewResponsibility: () => void;
    onEditRoutine: (routine: PlannerRoutine) => void;
    onSelectOccurrence: (occurrence: RoutineOccurrence) => void;
};

const isTab = (value: unknown): value is Tab => value === 'tasks' || value === 'routines';
const isView = (value: unknown): value is TaskView => value === 'planning' || value === 'due';
const isFilter = (value: unknown): value is ResponsibilityFilter => value === 'all' || value === 'inbox' || typeof value === 'number';
const taskUrl = (id: number) => `/tasks/${id}`;

function Chevron({ open }: { open: boolean }) {
    return (
        <svg aria-hidden="true" className={`h-3.5 w-3.5 shrink-0 transition-transform duration-200 ${open ? 'rotate-90' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="m9 5 7 7-7 7" /></svg>
    );
}

export default function TasksCard({
    tasks,
    responsibilities,
    routines,
    routinesToday,
    timezone,
    draggingTaskId,
    onSchedule,
    onDragTask,
    onNewRoutine,
    onNewResponsibility,
    onEditRoutine,
    onSelectOccurrence,
}: Props) {
    const toast = useToast();
    const { celebrate } = useCompanion();
    const [tab, setTab] = useStoredState<Tab>('pm.card.tab', 'tasks', isTab);
    const [view, setView] = useStoredState<TaskView>('pm.tasks.view', 'planning', isView);
    const [storedFilter, setFilter] = useStoredState<ResponsibilityFilter>('pm.tasks.filter', 'all', isFilter);
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [doneOpen, setDoneOpen] = useState(false);
    const [completing, setCompleting] = useState<Set<number>>(new Set());
    const [confirmDelete, setConfirmDelete] = useState<PlannerTask | null>(null);
    const [confirmClear, setConfirmClear] = useState(false);
    const [editing, setEditing] = useState<PlannerTask | null>(null);
    const [busy, setBusy] = useState(false);
    const [fresh, setFresh] = useState<Set<number>>(new Set());
    const known = useRef(new Set(tasks.map(task => task.id)));
    const undoable = useUndoableDelete(taskUrl, 'Task deleted');

    // Highlight tasks that were just added.
    useEffect(() => {
        const added = tasks.filter(task => !known.current.has(task.id)).map(task => task.id);

        known.current = new Set(tasks.map(task => task.id));
        if (added.length === 0) return;

        setFresh(new Set(added));
        const timer = window.setTimeout(() => setFresh(new Set()), 1300);

        return () => window.clearTimeout(timer);
    }, [tasks]);

    // A remembered responsibility may have been archived since; fall back to everything.
    const filter: ResponsibilityFilter = typeof storedFilter === 'number' && !responsibilities.some(item => item.id === storedFilter) ? 'all' : storedFilter;
    const visible = filterTasks(tasks, filter);
    const sections = buildSections(visible, view, timezone);
    const done = completedTasks(visible);
    const openCount = tasks.filter(task => !task.completed_at).length;
    const overdueCount = tasks.filter(task => dueState(task, timezone) === 'overdue').length;
    const allDone = completedTasks(tasks).length;
    const responsibilityOf = (task: PlannerTask) => responsibilities.find(item => item.id === task.responsibility_id) ?? null;

    const toggle = useCallback((task: PlannerTask) => {
        setCompleting(current => new Set(current).add(task.id));
        router.patch(`/tasks/${task.id}/completion`, { completed: !task.completed_at }, {
            preserveScroll: true,
            onSuccess: () => { if (!task.completed_at) celebrate(); },
            onError: () => toast.show({ message: 'Could not update that task. Please try again.' }),
            onFinish: () => setCompleting(current => {
                const next = new Set(current);
                next.delete(task.id);

                return next;
            }),
        });
    }, [toast, celebrate]);

    function requestDelete(task: PlannerTask) {
        if (task.calendar_sessions_count > 0) setConfirmDelete(task);
        else undoable.schedule(task.id);
    }

    function deleteNow(task: PlannerTask) {
        setBusy(true);
        router.delete(taskUrl(task.id), {
            preserveScroll: true,
            onSuccess: () => setConfirmDelete(null),
            onError: () => toast.show({ message: 'Could not delete that task. Please try again.' }),
            onFinish: () => setBusy(false),
        });
    }

    function clearCompleted() {
        setBusy(true);
        router.delete('/completed-tasks', {
            preserveScroll: true,
            onSuccess: () => { setConfirmClear(false); setDoneOpen(false); },
            onError: () => toast.show({ message: 'Could not clear completed tasks. Please try again.' }),
            onFinish: () => setBusy(false),
        });
    }

    function toggleSection(key: string) {
        setCollapsed(current => {
            const next = new Set(current);
            if (next.has(key)) next.delete(key);
            else next.add(key);

            return next;
        });
    }

    function startNewTask() {
        setTab('tasks');
        window.setTimeout(() => document.getElementById('new-task-title')?.focus(), 50);
    }

    const row = (task: PlannerTask) => (
        <TaskRow
            key={task.id}
            task={task}
            timezone={timezone}
            responsibility={responsibilityOf(task)}
            leaving={undoable.pending.has(task.id) || completing.has(task.id)}
            fresh={fresh.has(task.id)}
            dragging={draggingTaskId === task.id}
            onToggle={toggle}
            onPlan={onSchedule}
            onEdit={setEditing}
            onDelete={requestDelete}
            onDragStart={onDragTask}
            onDragEnd={() => onDragTask(null)}
        />
    );

    const sessionsText = (count: number) => `${count} ${count === 1 ? 'calendar session' : 'calendar sessions'}`;

    return (
        <section aria-labelledby="tasks-title" className="rounded-3xl border border-[var(--pm-border)] bg-white">
            <div className="flex items-start justify-between gap-3 px-5 pt-5">
                <div>
                    <h2 id="tasks-title" className="font-semibold">{tab === 'tasks' ? 'Tasks' : 'Routines'}</h2>
                    <p className="mt-1 text-xs text-[var(--pm-muted)]">
                        {tab === 'tasks'
                            ? <>{openCount} to do{overdueCount > 0 && <span className="font-medium text-[var(--pm-overdue)]"> · {overdueCount} overdue</span>}</>
                            : `${routines.length} ${routines.length === 1 ? 'routine' : 'routines'} · ${routinesToday.filter(item => !item.completed).length} left today`}
                    </p>
                </div>
                <Menu
                    label="Create new"
                    trigger="＋ New"
                    items={[
                        { label: 'New task', onSelect: startNewTask },
                        { label: 'New routine', onSelect: onNewRoutine },
                        { label: 'New responsibility', onSelect: onNewResponsibility },
                    ]}
                />
            </div>

            <div className="px-4 pt-4">
                <div
                    role="tablist"
                    aria-label="Tasks or routines"
                    className="pm-tabs"
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') setTab(tab === 'tasks' ? 'routines' : 'tasks');
                    }}
                >
                    {(['tasks', 'routines'] as Tab[]).map(name => (
                        <button
                            key={name}
                            type="button"
                            role="tab"
                            id={`tab-${name}`}
                            aria-selected={tab === name}
                            aria-controls={`panel-${name}`}
                            tabIndex={tab === name ? 0 : -1}
                            onClick={() => setTab(name)}
                            className="pm-tab"
                        >
                            {name === 'tasks' ? 'Tasks' : 'Routines'}
                        </button>
                    ))}
                </div>
            </div>

            {tab === 'routines' ? (
                <div role="tabpanel" id="panel-routines" aria-labelledby="tab-routines" className="pm-tab-panel">
                    <RoutinesTab
                        routines={routines}
                        today={routinesToday}
                        responsibilities={responsibilities}
                        timezone={timezone}
                        onNew={onNewRoutine}
                        onSelectOccurrence={onSelectOccurrence}
                        onEdit={onEditRoutine}
                    />
                </div>
            ) : (
                <div role="tabpanel" id="panel-tasks" aria-labelledby="tab-tasks" className="pm-tab-panel">
                    <div className="border-b border-[var(--pm-border)] px-4 pt-4 pb-4">
                        <AddTaskForm responsibilities={responsibilities} timezone={timezone} onCreateResponsibility={onNewResponsibility} />
                    </div>

                    <div className="flex flex-wrap items-center gap-2 px-4 pt-3">
                        <select aria-label="View" value={view} onChange={(event) => setView(event.target.value as TaskView)} className="pm-chip pm-chip--active">
                            {taskViews.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}
                        </select>
                        <select
                            aria-label="Show responsibility"
                            value={filter}
                            onChange={(event) => setFilter(event.target.value === 'all' || event.target.value === 'inbox' ? event.target.value : Number(event.target.value))}
                            className={`pm-chip max-w-44 ${filter === 'all' ? '' : 'pm-chip--active'}`}
                        >
                            <option value="all">All responsibilities</option>
                            <option value="inbox">Inbox</option>
                            {responsibilities.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                    </div>

                    <div className="px-2 pt-2 pb-3">
                        {sections.length === 0 && done.length === 0 && (
                            <div className="px-4 py-10 text-center">
                                <p className="text-sm font-medium">{tasks.length === 0 ? 'No tasks yet' : 'Nothing to show'}</p>
                                <p className="mx-auto mt-2 max-w-56 text-xs text-[var(--pm-muted)]">
                                    {tasks.length === 0 ? 'Add your first task above, then drag it onto the calendar to give it time.' : 'No tasks match this responsibility.'}
                                </p>
                            </div>
                        )}
                        {sections.length === 0 && done.length > 0 && (
                            <p className="px-4 py-6 text-center text-sm text-[var(--pm-muted)]">All caught up ✓</p>
                        )}

                        {sections.map(section => {
                            const open = !collapsed.has(section.key);

                            return (
                                <div key={section.key} className="mt-1">
                                    <button
                                        type="button"
                                        onClick={() => toggleSection(section.key)}
                                        aria-expanded={open}
                                        className="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-left"
                                    >
                                        <Chevron open={open} />
                                        <span className={`text-xs font-semibold tracking-wide uppercase ${section.tone === 'overdue' ? 'text-[var(--pm-overdue)]' : section.tone === 'today' ? 'text-[var(--pm-today)]' : 'text-[var(--pm-muted)]'}`}>{section.title}</span>
                                        <span className="ml-auto text-xs text-[var(--pm-muted)]">{section.tasks.length}</span>
                                    </button>
                                    <div className="pm-collapse" data-open={open}>
                                        <div><ul>{section.tasks.map(row)}</ul></div>
                                    </div>
                                </div>
                            );
                        })}

                        {done.length > 0 && (
                            <div className="mt-2 border-t border-[var(--pm-border)] pt-2">
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setDoneOpen(!doneOpen)}
                                        aria-expanded={doneOpen}
                                        className="flex flex-1 cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-left"
                                    >
                                        <Chevron open={doneOpen} />
                                        <span className="text-xs font-semibold tracking-wide text-[var(--pm-muted)] uppercase">Done</span>
                                        <span className="ml-auto text-xs text-[var(--pm-muted)]">{done.length}</span>
                                    </button>
                                    {filter === 'all' && (
                                        <button type="button" onClick={() => setConfirmClear(true)} className="pm-button pm-button--secondary pm-button--small">Clear</button>
                                    )}
                                </div>
                                <div className="pm-collapse" data-open={doneOpen}>
                                    <div><ul>{done.map(row)}</ul></div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {editing && <EditTaskDialog key={editing.id} task={editing} responsibilities={responsibilities} timezone={timezone} onClose={() => setEditing(null)} />}
            {confirmDelete && (
                <ConfirmDialog
                    title={`Delete “${confirmDelete.title}”?`}
                    description={`This also removes its ${sessionsText(confirmDelete.calendar_sessions_count)} from your calendar. This cannot be undone.`}
                    confirmLabel="Delete task"
                    busy={busy}
                    onConfirm={() => deleteNow(confirmDelete)}
                    onCancel={() => setConfirmDelete(null)}
                />
            )}
            {confirmClear && (
                <ConfirmDialog
                    title={`Delete ${allDone} completed ${allDone === 1 ? 'task' : 'tasks'}?`}
                    description="Their calendar sessions are removed too. This cannot be undone."
                    confirmLabel="Delete all"
                    busy={busy}
                    onConfirm={clearCompleted}
                    onCancel={() => setConfirmClear(false)}
                />
            )}
        </section>
    );
}
