import { useEffect, useRef, type ReactNode } from 'react';
import { formatDue } from '../../lib/deadlines';
import { courseTitle, kindLabels, statusOf, type SchoolAssignment } from '../../lib/school';
import Button from '../ui/button';

type Props = {
    item: SchoolAssignment;
    timezone: string;
    color: string;
    onToggle: (item: SchoolAssignment) => void;
    onClose: () => void;
};

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
            <dt className="text-sm text-[var(--pm-muted)]">{label}</dt>
            <dd className="min-w-0 text-right text-sm">{children}</dd>
        </div>
    );
}

/** Everything about one piece of work, with the ways to act on it. Nothing here changes Canvas itself. */
export default function SchoolAssignmentDialog({ item, timezone, color, onToggle, onClose }: Props) {
    const dialog = useRef<HTMLDialogElement>(null);
    const status = statusOf(item);
    const canToggle = item.task_id !== null && !item.submitted;

    useEffect(() => { dialog.current?.showModal(); }, []);

    const points = item.points_possible === null
        ? null
        : item.points_possible === 0
            ? 'No points'
            : `${item.score !== null ? `${item.score} of ` : ''}${item.points_possible} points`;

    return (
        <dialog ref={dialog} aria-labelledby="school-dialog-title" className="pm-dialog" onClose={onClose} onCancel={onClose}>
            <h2 id="school-dialog-title" className="text-xl font-medium break-words">{item.name}</h2>
            <p className="mt-2 flex items-center gap-2 text-sm text-[var(--pm-muted)]">
                <span aria-hidden="true" className="size-2 shrink-0 rounded-full" style={{ background: color }} />
                {courseTitle(item.course_name)}
            </p>

            <dl className="mt-5 divide-y divide-[var(--pm-border)]">
                <Fact label="Due">{item.due_at ? formatDue(item.due_at, true, timezone) : 'No due date'}</Fact>
                <Fact label="Type">{kindLabels[item.kind]}</Fact>
                {points && <Fact label="Points">{points}</Fact>}
                <Fact label="Status">
                    {status ? <span className={`pm-badge ${status.variant === 'ok' ? 'pm-badge--ok' : 'pm-badge--warn'}`}>{status.label}</span> : <span className="pm-badge">Not submitted</span>}
                </Fact>
                <Fact label="Planner task">{item.task_id === null ? 'None' : item.done ? 'Completed' : 'Open'}</Fact>
            </dl>

            <div className="pm-button-group mt-6 justify-end">
                <Button type="button" variant="secondary" onClick={onClose}>Close</Button>
                {canToggle && <Button type="button" variant="secondary" onClick={() => { onToggle(item); onClose(); }}>{item.done ? 'Reopen task' : 'Mark done'}</Button>}
                {item.url && (
                    <a href={item.url} target="_blank" rel="noopener noreferrer" className="pm-button">
                        View in Canvas
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" /></svg>
                    </a>
                )}
            </div>
        </dialog>
    );
}
