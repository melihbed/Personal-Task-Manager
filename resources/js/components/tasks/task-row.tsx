import type { DragEvent } from 'react';
import { formatLength } from '../../lib/routines';
import { courseTitle } from '../../lib/school';
import type { PlannerTask } from '../../lib/planner';
import DuePill from '../due-pill';
import { router } from '@inertiajs/react';
import Button from '../ui/button';
import Menu from '../ui/menu';

type Props = {
    task: PlannerTask;
    timezone: string;
    /** null means the Inbox. */
    responsibility: { name: string; color: string | null } | null;
    /** Collapsing out of the list (completed, or deleted with an undo window). */
    leaving: boolean;
    /** Just added; plays the entrance animation. */
    fresh: boolean;
    dragging: boolean;
    onToggle: (task: PlannerTask) => void;
    onPlan: (task: PlannerTask) => void;
    onEdit: (task: PlannerTask) => void;
    onDelete: (task: PlannerTask) => void;
    onDragStart: (task: PlannerTask) => void;
    onDragEnd: () => void;
};

/** A small card shown under the pointer while a task is dragged. */
function startDrag(event: DragEvent<HTMLElement>, task: PlannerTask) {
    const ghost = document.createElement('div');

    ghost.className = 'pm-drag-ghost';
    ghost.textContent = task.title;
    if (task.estimate_minutes) {
        const detail = document.createElement('small');

        detail.textContent = formatLength(task.estimate_minutes);
        ghost.appendChild(detail);
    }
    document.body.appendChild(ghost);

    event.dataTransfer.setData('text/plain', String(task.id));
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setDragImage(ghost, 20, 20);
    requestAnimationFrame(() => ghost.remove());
}

export default function TaskRow({ task, timezone, responsibility, leaving, fresh, dragging, onToggle, onPlan, onEdit, onDelete, onDragStart, onDragEnd }: Props) {
    const done = task.completed_at !== null;

    return (
        <li className={`pm-row ${fresh ? 'pm-enter' : ''}`} data-leaving={leaving} aria-hidden={leaving || undefined} inert={leaving}>
            <div className="pm-row__inner">
                <div className={`flex items-start gap-2 rounded-xl px-1.5 py-2.5 transition duration-150 hover:bg-[var(--pm-background)]/60 ${dragging ? 'pm-dragging' : ''}`}>
                    {done ? (
                        <span className="w-4 shrink-0" aria-hidden="true" />
                    ) : (
                        <span
                            role="button"
                            tabIndex={0}
                            draggable
                            className="pm-grip mt-0.5"
                            aria-label={`Drag “${task.title}” onto the calendar to plan it. Press Enter to plan with a form instead.`}
                            title="Drag onto the calendar"
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    onPlan(task);
                                }
                            }}
                            onDragStart={(event) => {
                                startDrag(event, task);
                                onDragStart(task);
                            }}
                            onDragEnd={onDragEnd}
                        >
                            <svg aria-hidden="true" width="12" height="16" viewBox="0 0 12 16" fill="currentColor"><circle cx="3" cy="3" r="1.3" /><circle cx="9" cy="3" r="1.3" /><circle cx="3" cy="8" r="1.3" /><circle cx="9" cy="8" r="1.3" /><circle cx="3" cy="13" r="1.3" /><circle cx="9" cy="13" r="1.3" /></svg>
                        </span>
                    )}

                    <button
                        type="button"
                        onClick={() => onToggle(task)}
                        aria-label={`${done ? 'Reopen' : 'Complete'} ${task.title}`}
                        aria-pressed={done}
                        className="pm-task-check mt-0.5"
                    >
                        {done && <span aria-hidden="true" className="text-xs">✓</span>}
                    </button>

                    <div className="min-w-0 flex-1">
                        <p className={`text-sm leading-5 break-words ${done ? 'text-[var(--pm-muted)] line-through' : ''}`}>{task.title}</p>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-[var(--pm-muted)]">
                            {task.due_at && <DuePill task={task} timezone={timezone} />}
                            {task.estimate_minutes && <span>{formatLength(task.estimate_minutes)}</span>}
                            {task.notes && <span title={task.notes}>≡ Notes</span>}
                            {(task.focus_rounds_count ?? 0) > 0 && <span title="Finished focus rounds on this task">◔ {task.focus_rounds_count} {task.focus_rounds_count === 1 ? 'focus round' : 'focus rounds'}</span>}
                            {task.calendar_sessions_count > 0 && <span>▦ {task.calendar_sessions_count} {task.calendar_sessions_count === 1 ? 'session' : 'sessions'}</span>}
                            {task.canvas_assignment && (
                                <span title="From Canvas">{courseTitle(task.canvas_assignment.course.name)}</span>
                            )}
                            <span className="inline-flex items-center gap-1">
                                <span aria-hidden="true" className="size-1.5 rounded-full" style={{ background: responsibility?.color ?? 'var(--pm-accent)' }} />
                                {responsibility?.name ?? 'Inbox'}
                            </span>
                        </div>
                    </div>

                    {!done && (
                        <Button type="button" variant="secondary" size="small" className="shrink-0" onClick={() => onPlan(task)} aria-label={`Plan ${task.title}`}>Plan</Button>
                    )}
                    <Menu
                        label={`Actions for ${task.title}`}
                        items={[
                            { label: 'Edit task…', onSelect: () => onEdit(task) },
                            ...(done ? [] : [{ label: 'Plan on calendar…', onSelect: () => onPlan(task) }, { label: 'Start focus…', onSelect: () => router.visit(`/focus?task=${task.id}`) }]),
                            { label: done ? 'Reopen' : 'Mark done', onSelect: () => onToggle(task) },
                            { label: 'Delete task', onSelect: () => onDelete(task), danger: true },
                        ]}
                    />
                </div>
            </div>
        </li>
    );
}
