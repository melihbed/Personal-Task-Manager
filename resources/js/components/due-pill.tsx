import { dueState, formatDue, type DueInfo } from '../lib/deadlines';

type Props = { task: DueInfo; timezone: string };

export default function DuePill({ task, timezone }: Props) {
    const state = dueState(task, timezone);
    if (!state || !task.due_at) return null;

    const label = formatDue(task.due_at, task.due_has_time, timezone);

    return (
        <span className={`pm-due pm-due--${state}`}>
            <svg aria-hidden="true" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="shrink-0"><path d="M5 21V4m0 0h11l-2 4 2 4H5" /></svg>
            <span className="pm-due__text">{state === 'overdue' ? `Overdue · ${label}` : label}</span>
        </span>
    );
}
