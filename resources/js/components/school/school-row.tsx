import { courseTitle, statusOf, type SchoolAssignment } from '../../lib/school';
import DuePill from '../due-pill';

type Props = {
    item: SchoolAssignment;
    timezone: string;
    color: string;
    leaving: boolean;
    onOpen: (item: SchoolAssignment) => void;
    onToggle: (item: SchoolAssignment) => void;
};

/** One piece of Canvas work, laid out like a task row: check circle, title, then small facts underneath. */
export default function SchoolRow({ item, timezone, color, leaving, onOpen, onToggle }: Props) {
    const status = statusOf(item);
    const canToggle = item.task_id !== null && !item.submitted;

    return (
        <li className="pm-row" data-leaving={leaving} aria-hidden={leaving || undefined} inert={leaving}>
            <div className="pm-row__inner">
                <div className="flex items-start gap-3 rounded-xl px-2 py-2.5 transition duration-150 hover:bg-[var(--pm-background)]/60">
                    {canToggle ? (
                        <button
                            type="button"
                            onClick={() => onToggle(item)}
                            aria-label={`${item.done ? 'Reopen' : 'Complete'} ${item.name}`}
                            aria-pressed={item.done}
                            className="pm-task-check mt-0.5"
                        >
                            {item.done && <span aria-hidden="true" className="text-xs">✓</span>}
                        </button>
                    ) : (
                        <span
                            role="img"
                            aria-label={item.submitted ? 'Submitted in Canvas' : 'No task to complete'}
                            title={item.submitted ? 'Submitted in Canvas' : 'No task for this one'}
                            className={`mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full ${item.submitted ? 'bg-[#16a34a] text-white' : 'border border-dashed border-[var(--pm-border)]'}`}
                        >
                            {item.submitted && <svg aria-hidden="true" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.4" strokeLinecap="round" strokeLinejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>}
                        </span>
                    )}

                    <div className="min-w-0 flex-1">
                        <button
                            type="button"
                            onClick={() => onOpen(item)}
                            aria-label={`Details for ${item.name}`}
                            className={`block w-full cursor-pointer text-left text-sm leading-5 break-words ${item.done ? 'text-[var(--pm-muted)] line-through' : ''}`}
                        >
                            {item.name}
                        </button>
                        <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-[var(--pm-muted)]">
                            {item.due_at ? <DuePill task={{ due_at: item.due_at, due_has_time: true, completed_at: item.done ? item.due_at : null }} timezone={timezone} /> : <span>No due date</span>}
                            {status && <span className={`pm-badge ${status.variant === 'ok' ? 'pm-badge--ok' : 'pm-badge--warn'}`}>{status.label}</span>}
                            {item.points_possible === 0 && <span className="rounded-md bg-[var(--pm-background)] px-1.5 py-0.5">No points</span>}
                            <span className="inline-flex min-w-0 items-center gap-1.5">
                                <span aria-hidden="true" className="size-1.5 shrink-0 rounded-full" style={{ background: color }} />
                                <span className="truncate" title={item.course_name}>{courseTitle(item.course_name)}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </li>
    );
}
