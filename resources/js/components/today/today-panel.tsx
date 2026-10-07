import { useState } from 'react';
import { useNow } from '../../lib/use-now';
import { dateLabel, timeLabel, zonedParts, type GoogleEvent, type PlannerSession, type PlannerTask, type RoutineOccurrence } from '../../lib/planner';
import { courseTitle } from '../../lib/school';
import { buildAgenda, buildAttention, type AgendaItem } from '../../lib/today';
import DuePill from '../due-pill';

type Props = {
    tasks: PlannerTask[];
    responsibilities: { id: number; name: string; color: string | null }[];
    sessions: PlannerSession[];
    routines: RoutineOccurrence[];
    /** Undefined while Google is still loading. */
    events: GoogleEvent[] | undefined;
    timezone: string;
    onPlanTask: (task: PlannerTask) => void;
    onSelectSession: (session: PlannerSession) => void;
    onSelectOccurrence: (occurrence: RoutineOccurrence) => void;
    onSelectEvent: (event: GoogleEvent) => void;
};

const ATTENTION_LIMIT = 4;
const AGENDA_LIMIT = 5;

function Heading({ children, count, tone }: { children: string; count?: number; tone?: 'overdue' }) {
    return (
        <div className="flex items-center gap-2 px-2 pt-3 pb-1">
            <span className={`text-xs font-semibold tracking-wide uppercase ${tone === 'overdue' ? 'text-[var(--pm-overdue)]' : 'text-[var(--pm-muted)]'}`}>{children}</span>
            {count !== undefined && <span className="ml-auto text-xs text-[var(--pm-muted)]">{count}</span>}
        </div>
    );
}

function MoreButton({ open, hidden, onClick }: { open: boolean; hidden: number; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} aria-expanded={open} className="mt-1 w-full cursor-pointer rounded-lg px-2 py-1.5 text-left text-xs text-[var(--pm-muted)] hover:bg-[var(--pm-background)]/60">
            {open ? 'Show less' : `Show ${hidden} more`}
        </button>
    );
}

/**
 * A glance at the day: what needs attention (overdue, or due in the next 48 hours) and what is on next,
 * merged from the calendar, planned sessions, routines and deadlines. Clicking an item opens it.
 */
export default function TodayPanel({ tasks, responsibilities, sessions, routines, events, timezone, onPlanTask, onSelectSession, onSelectOccurrence, onSelectEvent }: Props) {
    const now = useNow();
    const [attentionOpen, setAttentionOpen] = useState(false);
    const [agendaOpen, setAgendaOpen] = useState(false);
    const [earlierOpen, setEarlierOpen] = useState(false);

    const { overdue, soon } = buildAttention(tasks, now, timezone);
    const attention = [...overdue, ...soon];
    const agenda = buildAgenda({ events: events ?? [], sessions, routines, tasks }, now, timezone);
    const earlier = agenda.filter(item => item.when === 'past');
    const upcoming = agenda.filter(item => item.when !== 'past');
    const shownAttention = attentionOpen ? attention : attention.slice(0, ATTENTION_LIMIT);
    const shownAgenda = agendaOpen ? upcoming : upcoming.slice(0, AGENDA_LIMIT);
    const today = zonedParts(now, timezone).date;
    const responsibilityOf = (task: PlannerTask) => responsibilities.find(item => item.id === task.responsibility_id) ?? null;

    function open(item: AgendaItem) {
        const source = item.source;

        if (source.kind === 'event') onSelectEvent(source.event);
        else if (source.kind === 'session') onSelectSession(source.session);
        else if (source.kind === 'routine') onSelectOccurrence(source.occurrence);
        else onPlanTask(source.task);
    }

    const agendaRow = (item: AgendaItem) => (
        <li key={item.key}>
            <button type="button" onClick={() => open(item)} className={`flex w-full cursor-pointer items-stretch gap-3 rounded-xl px-2 py-2 text-left transition duration-150 hover:bg-[var(--pm-background)]/60 ${item.when === 'past' ? 'opacity-60' : ''}`}>
                <span className="w-14 shrink-0 pt-0.5 text-xs text-[var(--pm-muted)] tabular-nums">{item.allDay ? 'All day' : timeLabel(item.startsAt!, timezone)}</span>
                <span aria-hidden="true" className="w-1 shrink-0 rounded-full" style={{ background: item.color ?? 'var(--pm-border)' }} />
                <span className="min-w-0 flex-1">
                    <span className="block text-sm leading-5 break-words">{item.title}</span>
                    <span className="mt-0.5 flex items-center gap-2 text-[11px] text-[var(--pm-muted)]">
                        {item.when === 'now' && <span className="pm-badge pm-badge--ok">Now</span>}
                        <span className="truncate">{item.detail}</span>
                    </span>
                </span>
            </button>
        </li>
    );

    return (
        <section aria-labelledby="today-title" className="rounded-3xl border border-[var(--pm-border)] bg-white px-3 pt-5 pb-3">
            <div className="px-2">
                <h2 id="today-title" className="font-semibold">Today</h2>
                <p className="mt-1 text-xs text-[var(--pm-muted)]">{dateLabel(today, { weekday: 'long', month: 'long', day: 'numeric' })}</p>
            </div>

            {attention.length > 0 ? (
                <>
                    <Heading count={attention.length} tone={overdue.length > 0 ? 'overdue' : undefined}>
                        {overdue.length > 0 ? `Needs attention, ${overdue.length} overdue` : 'Needs attention'}
                    </Heading>
                    <ul>
                        {shownAttention.map(task => {
                            const responsibility = responsibilityOf(task);

                            return (
                                <li key={task.id}>
                                    <button type="button" onClick={() => onPlanTask(task)} aria-label={`Plan ${task.title}`} className="block w-full cursor-pointer rounded-xl px-2 py-2 text-left transition duration-150 hover:bg-[var(--pm-background)]/60">
                                        <span className="block text-sm leading-5 break-words">{task.title}</span>
                                        <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-[var(--pm-muted)]">
                                            <DuePill task={task} timezone={timezone} />
                                            <span className="inline-flex min-w-0 items-center gap-1.5">
                                                <span aria-hidden="true" className="size-1.5 shrink-0 rounded-full" style={{ background: responsibility?.color ?? 'var(--pm-accent)' }} />
                                                <span className="truncate">{task.canvas_assignment ? courseTitle(task.canvas_assignment.course.name) : responsibility?.name ?? 'Inbox'}</span>
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                    {attention.length > ATTENTION_LIMIT && <MoreButton open={attentionOpen} hidden={attention.length - ATTENTION_LIMIT} onClick={() => setAttentionOpen(value => !value)} />}
                </>
            ) : (
                <p className="mt-3 flex items-center gap-2 px-2 text-xs text-[var(--pm-muted)]">
                    <span aria-hidden="true" className="inline-flex size-4 items-center justify-center rounded-full bg-[#16a34a] text-white"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.6" strokeLinecap="round" strokeLinejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg></span>
                    Nothing overdue or due in the next 48 hours
                </p>
            )}

            <Heading>Up next</Heading>
            {events === undefined && <div role="status" aria-label="Loading today's calendar" className="mx-2 mb-2 h-9 animate-pulse rounded-xl bg-[var(--pm-background)]" />}
            {upcoming.length === 0 && events !== undefined && (
                <p className="px-2 pt-1 pb-2 text-xs text-[var(--pm-muted)]">{agenda.length > 0 ? 'Nothing else today.' : 'Nothing scheduled today. Drag a task onto the calendar to give it time.'}</p>
            )}
            {upcoming.length > 0 && <ul>{shownAgenda.map(agendaRow)}</ul>}
            {upcoming.length > AGENDA_LIMIT && <MoreButton open={agendaOpen} hidden={upcoming.length - AGENDA_LIMIT} onClick={() => setAgendaOpen(value => !value)} />}

            {earlier.length > 0 && (
                <div className="mt-1 border-t border-[var(--pm-border)] pt-1">
                    <button type="button" onClick={() => setEarlierOpen(value => !value)} aria-expanded={earlierOpen} className="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-left">
                        <svg aria-hidden="true" className={`h-3.5 w-3.5 shrink-0 transition-transform duration-200 ${earlierOpen ? 'rotate-90' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="m9 6 6 6-6 6" /></svg>
                        <span className="text-xs font-semibold tracking-wide text-[var(--pm-muted)] uppercase">Earlier today</span>
                        <span className="ml-auto text-xs text-[var(--pm-muted)]">{earlier.length}</span>
                    </button>
                    <div className="pm-collapse" data-open={earlierOpen}>
                        <div><ul>{earlier.map(agendaRow)}</ul></div>
                    </div>
                </div>
            )}
        </section>
    );
}
