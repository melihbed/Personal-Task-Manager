import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { durations, priorityLabels, type Priority } from '../lib/task-options';
import DuePicker from './due-picker';
import Button from './ui/button';
import Popover from './ui/popover';

type ResponsibilityOption = { id: number; name: string };
type Props = {
    /** When provided, shows a picker (Inbox plus these). Omit to lock the task to `responsibilityId`. */
    responsibilities?: ResponsibilityOption[];
    responsibilityId?: number | null;
    /** Timezone used for deadlines. Defaults to the browser's. */
    timezone?: string;
    /** Adds a "New responsibility…" entry to the picker that calls this. */
    onCreateResponsibility?: () => void;
};

const NEW_RESPONSIBILITY = 'new';

export default function AddTaskForm({
    responsibilities,
    responsibilityId = null,
    timezone = Intl.DateTimeFormat().resolvedOptions().timeZone,
    onCreateResponsibility,
}: Props) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        title: string;
        responsibility_id: number | null;
        due_at: string | null;
        due_has_time: boolean;
        estimate_minutes: number | null;
        priority: Priority;
    }>({
        title: '',
        responsibility_id: responsibilityId,
        due_at: null,
        due_has_time: true,
        estimate_minutes: null,
        priority: 'normal',
    });
    const [focused, setFocused] = useState(false);
    const knownIds = useRef(new Set(responsibilities?.map((responsibility) => responsibility.id)));

    // A responsibility created from the picker's "New responsibility…" entry is selected automatically.
    useEffect(() => {
        if (!responsibilities) return;

        const added = responsibilities.find((responsibility) => !knownIds.current.has(responsibility.id));
        knownIds.current = new Set(responsibilities.map((responsibility) => responsibility.id));

        if (added) setData('responsibility_id', added.id);
    }, [responsibilities]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/tasks', {
            preserveScroll: true,
            onSuccess: () => reset('title', 'due_at', 'due_has_time', 'estimate_minutes', 'priority'),
        });
    }

    const details = [
        data.estimate_minutes ? durations.find((duration) => duration.minutes === data.estimate_minutes)?.label : null,
        data.priority !== 'normal' ? priorityLabels[data.priority] : null,
    ].filter(Boolean).join(' · ');
    const messages = Object.values(errors);
    const touched = data.title !== '' || data.due_at !== null || data.estimate_minutes !== null || data.priority !== 'normal' || data.responsibility_id !== responsibilityId;
    const expanded = focused || touched;

    return (
        <form
            onSubmit={submit}
            onFocus={() => setFocused(true)}
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setFocused(false);
            }}
        >
            <label htmlFor="new-task-title" className="text-sm font-medium">Add a task</label>
            <div className="mt-2 flex items-center gap-2">
                <input
                    id="new-task-title"
                    value={data.title}
                    onChange={(event) => setData('title', event.target.value)}
                    placeholder="What do you need to do?"
                    maxLength={255}
                    required
                    aria-invalid={!!errors.title}
                    className="pm-input pm-input--flush min-w-0 flex-1"
                />
                <Button
                    type="submit"
                    loading={processing}
                    loadingLabel="Adding task"
                    disabled={data.title.trim() === ''}
                    aria-label="Add task"
                    title="Add task"
                    className="pm-button--icon"
                >
                    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5m-6 6 6-6 6 6" /></svg>
                </Button>
            </div>

            <div className="pm-collapse" data-open={expanded}>
                <div>
            <div className="mt-3 flex flex-wrap gap-2" inert={!expanded}>
                {responsibilities && (
                    <select
                        aria-label="Responsibility"
                        value={data.responsibility_id ?? ''}
                        onChange={(event) => {
                            if (event.target.value === NEW_RESPONSIBILITY) {
                                onCreateResponsibility?.();
                                return;
                            }
                            setData('responsibility_id', event.target.value === '' ? null : Number(event.target.value));
                        }}
                        className="pm-chip pm-chip--active max-w-40"
                    >
                        <option value="">Inbox</option>
                        {responsibilities.map((responsibility) => (
                            <option key={responsibility.id} value={responsibility.id}>{responsibility.name}</option>
                        ))}
                        {onCreateResponsibility && <option value={NEW_RESPONSIBILITY}>＋ New responsibility…</option>}
                    </select>
                )}

                <DuePicker
                    value={data.due_at ? { dueAt: data.due_at, hasTime: data.due_has_time } : null}
                    onChange={(due) => {
                        setData((current) => ({
                            ...current,
                            due_at: due?.dueAt ?? null,
                            due_has_time: due?.hasTime ?? true,
                        }));
                    }}
                    timezone={timezone}
                />

                <Popover ariaLabel="More details" active={details !== ''} label={details || '⋯'}>
                    {() => (
                        <div className="space-y-3 px-2.5 py-2">
                            <div>
                                <label htmlFor="task-duration" className="text-xs font-medium">How long will it take?</label>
                                <select
                                    id="task-duration"
                                    value={data.estimate_minutes ?? ''}
                                    onChange={(event) => setData('estimate_minutes', event.target.value === '' ? null : Number(event.target.value))}
                                    className="pm-input pm-input--flush mt-1.5 px-2.5 py-2 text-xs"
                                >
                                    <option value="">Not set</option>
                                    {durations.map((duration) => (
                                        <option key={duration.minutes} value={duration.minutes}>{duration.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label htmlFor="task-priority" className="text-xs font-medium">Priority</label>
                                <select
                                    id="task-priority"
                                    value={data.priority}
                                    onChange={(event) => setData('priority', event.target.value as Priority)}
                                    className="pm-input pm-input--flush mt-1.5 px-2.5 py-2 text-xs"
                                >
                                    {(Object.keys(priorityLabels) as Priority[]).map((priority) => (
                                        <option key={priority} value={priority}>{priorityLabels[priority]}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    )}
                </Popover>
            </div>
                </div>
            </div>

            {messages.map((message, index) => (
                <p key={index} role="alert" className="mt-2 text-sm text-red-700">{message}</p>
            ))}
        </form>
    );
}
