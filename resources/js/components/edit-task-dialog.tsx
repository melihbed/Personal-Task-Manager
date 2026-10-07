import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { dueDay } from '../lib/deadlines';
import { localToISO, zonedParts, type PlannerTask } from '../lib/planner';
import { durationOptions, priorityLabels, type Priority } from '../lib/task-options';
import Button from './ui/button';
import Field from './ui/field';

export type EditableTask = Pick<PlannerTask, 'id' | 'title' | 'notes' | 'due_at' | 'due_has_time' | 'estimate_minutes' | 'priority' | 'responsibility_id'>;

type Props = {
    task: EditableTask;
    /** Omit to leave the responsibility as it is (for example on a responsibility's own page). */
    responsibilities?: { id: number; name: string }[];
    /** The calendar timezone, used to read and write the deadline. */
    timezone: string;
    onClose: () => void;
};

/** Change any detail of a task. Only the planner changes; a task copied from Google stays independent of it. */
export default function EditTaskDialog({ task, responsibilities, timezone, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const { data, setData, patch, processing, errors, transform } = useForm({
        title: task.title,
        notes: task.notes ?? '',
        responsibility_id: task.responsibility_id,
        estimate_minutes: task.estimate_minutes,
        priority: task.priority as Priority,
    });
    const [date, setDate] = useState(dueDay({ ...task, completed_at: null }, timezone) ?? '');
    const [time, setTime] = useState(task.due_has_time && task.due_at ? zonedParts(new Date(task.due_at), timezone).time : '');
    const [deadlineError, setDeadlineError] = useState('');

    useEffect(() => { dialog.current?.showModal(); }, []);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setDeadlineError('');

        let dueAt: string | null = null;
        let hasTime = true;

        if (!date && time) {
            setDeadlineError('Pick a date for that time, or clear the time.');
            return;
        }

        if (date && time) {
            try {
                dueAt = localToISO(date, time, timezone);
            } catch (error) {
                setDeadlineError((error as Error).message);
                return;
            }
        } else if (date) {
            // A date-only deadline is stored as noon UTC on that date.
            dueAt = `${date}T12:00:00Z`;
            hasTime = false;
        }

        transform((current) => ({ ...current, notes: current.notes.trim() === '' ? null : current.notes, due_at: dueAt, due_has_time: hasTime }));
        patch(`/tasks/${task.id}`, { preserveScroll: true, onSuccess: () => onClose() });
    }

    const others = Object.entries(errors).filter(([field]) => !['title'].includes(field)).map(([, message]) => message);

    return (
        <dialog
            ref={dialog}
            aria-labelledby="edit-task-title"
            className="pm-dialog"
            onCancel={(event) => { if (processing) event.preventDefault(); else onClose(); }}
            onClose={onClose}
        >
            <form onSubmit={submit} className="space-y-5">
                <h2 id="edit-task-title" className="text-xl font-medium">Edit task</h2>

                <Field
                    id="edit-task-name"
                    label="Title"
                    value={data.title}
                    onChange={(event) => setData('title', event.target.value)}
                    maxLength={255}
                    error={errors.title}
                    autoFocus
                    required
                />

                <div>
                    <label htmlFor="edit-task-notes" className="text-sm font-medium">Notes</label>
                    <textarea id="edit-task-notes" rows={3} maxLength={5000} value={data.notes} onChange={(event) => setData('notes', event.target.value)} className="pm-input" />
                </div>

                {responsibilities && (
                    <div>
                        <label htmlFor="edit-task-responsibility" className="text-sm font-medium">Responsibility</label>
                        <select
                            id="edit-task-responsibility"
                            value={data.responsibility_id ?? ''}
                            onChange={(event) => setData('responsibility_id', event.target.value === '' ? null : Number(event.target.value))}
                            className="pm-input cursor-pointer"
                        >
                            <option value="">Inbox</option>
                            {responsibilities.map(responsibility => <option key={responsibility.id} value={responsibility.id}>{responsibility.name}</option>)}
                        </select>
                    </div>
                )}

                <fieldset>
                    <legend className="text-sm font-medium">Deadline</legend>
                    <div className="mt-1 grid grid-cols-2 gap-4">
                        <div>
                            <label htmlFor="edit-task-date" className="sr-only">Deadline date</label>
                            <input id="edit-task-date" type="date" value={date} onChange={(event) => setDate(event.target.value)} className="pm-input pm-input--flush" />
                        </div>
                        <div>
                            <label htmlFor="edit-task-time" className="sr-only">Deadline time (optional)</label>
                            <input id="edit-task-time" type="time" value={time} onChange={(event) => setTime(event.target.value)} className="pm-input pm-input--flush" />
                        </div>
                    </div>
                    <div className="mt-1.5 flex items-center justify-between gap-3 text-xs text-[var(--pm-muted)]">
                        <span>The time is optional. {timezone.replaceAll('_', ' ')}.</span>
                        {(date || time) && <button type="button" onClick={() => { setDate(''); setTime(''); }} className="pm-link">Clear deadline</button>}
                    </div>
                    {deadlineError && <p role="alert" className="mt-1 text-sm text-red-700">{deadlineError}</p>}
                </fieldset>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label htmlFor="edit-task-duration" className="text-sm font-medium">How long will it take?</label>
                        <select
                            id="edit-task-duration"
                            value={data.estimate_minutes ?? ''}
                            onChange={(event) => setData('estimate_minutes', event.target.value === '' ? null : Number(event.target.value))}
                            className="pm-input cursor-pointer"
                        >
                            <option value="">Not set</option>
                            {durationOptions(task.estimate_minutes).map(option => <option key={option.minutes} value={option.minutes}>{option.label}</option>)}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="edit-task-priority" className="text-sm font-medium">Priority</label>
                        <select id="edit-task-priority" value={data.priority} onChange={(event) => setData('priority', event.target.value as Priority)} className="pm-input cursor-pointer">
                            {(Object.keys(priorityLabels) as Priority[]).map(priority => <option key={priority} value={priority}>{priorityLabels[priority]}</option>)}
                        </select>
                    </div>
                </div>

                {others.map((message, index) => <p key={index} role="alert" className="text-sm text-red-700">{message}</p>)}

                <div className="pm-button-group justify-end">
                    <Button type="button" variant="secondary" disabled={processing} onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={processing} loadingLabel="Saving task" disabled={data.title.trim() === ''}>Save</Button>
                </div>
            </form>
        </dialog>
    );
}
