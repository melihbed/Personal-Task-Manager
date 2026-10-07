import { useEffect, useRef, useState, type DragEvent } from 'react';
import { dueDay, dueMinutes, dueState, formatDue } from '../lib/deadlines';
import { addDays, dateLabel, dayLayout, overlaps, timeLabel, zonedParts, type GoogleEvent, type PlannerSession, type PlannerTask, type RoutineOccurrence } from '../lib/planner';

type Props = {
    weekStart: string;
    timezone: string;
    sessions: PlannerSession[];
    onSelect: (session: PlannerSession) => void;
    /** Occurrences of recurring routines for this week. */
    routineOccurrences: RoutineOccurrence[];
    onSelectRoutine: (occurrence: RoutineOccurrence) => void;
    /** Events from the user's Google Calendar: never dragged. Clicking one opens its details. */
    googleEvents: GoogleEvent[];
    onSelectGoogle: (event: GoogleEvent) => void;
    googleLoading: boolean;
    /** Unfinished tasks that have a deadline. */
    deadlines: PlannerTask[];
    onSelectDeadline: (task: PlannerTask) => void;
    /** The task being dragged from the list, and the drop handler (minutes since midnight, snapped to 15). */
    draggingTask: PlannerTask | null;
    onDropTask: (date: string, minutes: number) => void;
    /** A session or one routine occurrence was dragged to a new day or time; minutes is the new start. */
    onMoveSession: (session: PlannerSession, date: string, minutes: number) => void;
    onMoveRoutine: (occurrence: RoutineOccurrence, date: string, minutes: number) => void;
};

/** Anything drawn as a block in the time grid: a work session or one day of a routine. */
type Block = {
    key: string;
    title: string;
    subtitle: string;
    color: string | null;
    completed: boolean;
    starts_at: string;
    ends_at: string;
    session?: PlannerSession;
    routine?: RoutineOccurrence;
    google?: GoogleEvent;
};

const HOUR_HEIGHT = 56;

const clock = (minutes: number) => `${Math.floor(minutes / 60) % 12 || 12}:${String(minutes % 60).padStart(2, '0')} ${minutes < 720 ? 'AM' : 'PM'}`;
const lengthInMinutes = (block: Pick<Block, 'starts_at' | 'ends_at'>) => (Date.parse(block.ends_at) - Date.parse(block.starts_at)) / 60000;

export default function WeeklyCalendar({
    weekStart,
    timezone,
    sessions,
    onSelect,
    routineOccurrences,
    onSelectRoutine,
    googleEvents,
    onSelectGoogle,
    googleLoading,
    deadlines,
    onSelectDeadline,
    draggingTask,
    onDropTask,
    onMoveSession,
    onMoveRoutine,
}: Props) {
    const today = zonedParts(new Date(), timezone).date;
    const dates = Array.from({ length: 7 }, (_, index) => addDays(weekStart, index));
    const [selected, setSelected] = useState(today >= weekStart && today <= dates[6] ? today : weekStart);
    const viewport = useRef<HTMLDivElement>(null);
    const [hover, setHover] = useState<{ date: string; minutes: number } | null>(null);
    const [draggingBlock, setDraggingBlock] = useState<{ block: Block; grabMinutes: number } | null>(null);

    const blocks: Block[] = [
        ...sessions.map((session): Block => ({
            key: `session-${session.id}`,
            title: session.title,
            subtitle: session.responsibility_name,
            color: session.color,
            completed: session.completed,
            starts_at: session.starts_at,
            ends_at: session.ends_at,
            session,
        })),
        ...routineOccurrences.map((routine): Block => ({
            key: `routine-${routine.routine_id}-${routine.occurs_on}`,
            title: routine.title,
            subtitle: routine.responsibility_name,
            color: routine.color,
            completed: routine.completed,
            starts_at: routine.starts_at,
            ends_at: routine.ends_at,
            routine,
        })),
        ...googleEvents
            .filter(event => !event.all_day && event.starts_at && event.ends_at)
            .map((event): Block => ({
                key: `google-${event.id}`,
                title: event.title,
                subtitle: event.calendar,
                color: event.color,
                completed: false,
                starts_at: event.starts_at as string,
                ends_at: event.ends_at as string,
                google: event,
            })),
    ];
    const conflicts = new Set(
        blocks.filter(a => blocks.some(b => a.key !== b.key && (!a.google || !b.google) && overlaps(a.starts_at, a.ends_at, b.starts_at, b.ends_at))).map(block => block.key),
    );

    // A block that is new, or was moved, pops in. Changing the week or the first render does not animate.
    const signature = (block: Block) => `${block.key}@${block.starts_at}`;
    const blockSignatures = blocks.map(signature).join('|');
    const knownBlocks = useRef<{ week: string; signatures: Set<string> } | null>(null);
    const [popping, setPopping] = useState<Set<string>>(new Set());

    useEffect(() => {
        const signatures = new Set(blocks.map(signature));
        const previous = knownBlocks.current;
        const added = previous && previous.week === weekStart ? [...signatures].filter(item => !previous.signatures.has(item)) : [];

        knownBlocks.current = { week: weekStart, signatures };
        if (added.length === 0) return;

        setPopping(new Set(added));
        const timer = window.setTimeout(() => setPopping(new Set()), 450);

        return () => window.clearTimeout(timer);
        // blocks is rebuilt on every render; its signatures string is the real dependency.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [blockSignatures, weekStart]);

    const googleAllDay = (date: string) => googleEvents.filter(event => event.all_day && event.start_date && event.end_date && event.start_date <= date && date < event.end_date);
    const hasStrip = dates.some(date => googleAllDay(date).length > 0);

    const dateOnlyDeadlines = (date: string) => deadlines.filter(task => !task.due_has_time && dueDay(task, timezone) === date);
    const hasDateOnlyDeadlines = dates.some(date => dateOnlyDeadlines(date).length > 0);
    const weekDeadlineCount = deadlines.filter(task => dates.includes(dueDay(task, timezone) ?? '')).length;

    /** Minutes since midnight under the pointer, minus where a dragged block was grabbed, snapped to 15 minutes. */
    const snapMinutes = (event: DragEvent<HTMLDivElement>, grabMinutes = 0) => {
        const rect = event.currentTarget.getBoundingClientRect();

        return Math.min(1425, Math.max(0, Math.round(((event.clientY - rect.top) / HOUR_HEIGHT * 60 - grabMinutes) / 15) * 15));
    };

    function select(block: Block) {
        if (block.google) onSelectGoogle(block.google);
        else if (block.session) onSelect(block.session);
        else if (block.routine) onSelectRoutine(block.routine);
    }

    function drop(event: DragEvent<HTMLDivElement>, date: string) {
        event.preventDefault();

        const minutes = snapMinutes(event, draggingBlock?.grabMinutes);
        const moved = draggingBlock?.block;

        setHover(null);
        setDraggingBlock(null);

        if (moved?.session) onMoveSession(moved.session, date, minutes);
        else if (moved?.routine) onMoveRoutine(moved.routine, date, minutes);
        else if (draggingTask) onDropTask(date, minutes);
    }

    useEffect(() => {
        if (!draggingTask && !draggingBlock) setHover(null);
    }, [draggingTask, draggingBlock]);

    useEffect(() => {
        setSelected(today >= weekStart && today <= addDays(weekStart, 6) ? today : weekStart);
        if (viewport.current) viewport.current.scrollTop = 7 * HOUR_HEIGHT;
    }, [weekStart, today]);

    const previewMinutes = draggingBlock ? lengthInMinutes(draggingBlock.block) : (draggingTask?.estimate_minutes ?? 30);
    const previewTitle = draggingBlock ? draggingBlock.block.title : draggingTask?.title;

    return (
        <div className="overflow-hidden rounded-2xl border border-[var(--pm-border)] bg-white">
            <div className="flex border-b border-[var(--pm-border)]">
                <div className="w-14 shrink-0" />
                {dates.map(date => (
                    <button
                        key={date}
                        type="button"
                        onClick={() => setSelected(date)}
                        aria-pressed={selected === date}
                        className={`pm-calendar-day min-w-0 flex-1 px-1 py-3 text-center ${selected === date ? 'bg-[var(--pm-background)] md:bg-transparent' : ''}`}
                    >
                        <span className="block text-[10px] text-[var(--pm-muted)]">{dateLabel(date, { weekday: 'short' })}</span>
                        <span className={`mx-auto mt-1 flex h-7 w-7 items-center justify-center rounded-full text-sm ${date === today ? 'bg-[var(--pm-text)] text-white' : ''}`}>
                            {dateLabel(date, { day: 'numeric' })}
                        </span>
                    </button>
                ))}
            </div>

            {(hasDateOnlyDeadlines || hasStrip) && (
                <div className="flex border-b border-[var(--pm-border)]">
                    <div className="flex w-14 shrink-0 items-center justify-end pr-2 text-[10px] text-[var(--pm-muted)]">Due</div>
                    {dates.map(date => {
                        const items = dateOnlyDeadlines(date);
                        const allDay = googleAllDay(date);

                        return (
                            <div key={date} className={`min-w-0 flex-1 space-y-1 border-l border-[var(--pm-border)] p-1 ${selected === date ? 'block' : 'hidden md:block'}`}>
                                {items.slice(0, 2).map(task => (
                                    <button
                                        key={task.id}
                                        type="button"
                                        onClick={() => onSelectDeadline(task)}
                                        title={`Due ${formatDue(task.due_at ?? '', false, timezone)} · ${task.title}`}
                                        aria-label={`Deadline: ${task.title}, ${formatDue(task.due_at ?? '', false, timezone)}${dueState(task, timezone) === 'overdue' ? ', overdue' : ''}`}
                                        className={`pm-due pm-due--${dueState(task, timezone)} w-full`}
                                    >
                                        <span className="pm-due__text">{task.title}</span>
                                    </button>
                                ))}
                                {items.length > 2 && (
                                    <p className="px-1 text-[10px] text-[var(--pm-muted)]" title={items.slice(2).map(task => task.title).join(', ')}>+{items.length - 2} more</p>
                                )}
                                {allDay.slice(0, 2).map(event => (
                                    <button
                                        key={event.id}
                                        type="button"
                                        onClick={() => onSelectGoogle(event)}
                                        title={`${event.title} · ${event.calendar} (Google Calendar)`}
                                        aria-label={`All day: ${event.title}, from Google Calendar`}
                                        className="pm-due w-full"
                                        style={{ borderLeft: `3px solid ${event.color ?? 'var(--pm-muted)'}` }}
                                    >
                                        <span className="pm-due__text"><span className="mr-1 font-semibold">G</span>{event.title}</span>
                                    </button>
                                ))}
                                {allDay.length > 2 && (
                                    <p className="px-1 text-[10px] text-[var(--pm-muted)]" title={allDay.slice(2).map(event => event.title).join(', ')}>+{allDay.length - 2} more from Google</p>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            <div ref={viewport} className="pm-calendar-scroll overflow-y-auto" style={{ height: 540 }}>
                <div className="relative flex" style={{ height: 24 * HOUR_HEIGHT }}>
                    <div className="relative w-14 shrink-0 bg-white">
                        {Array.from({ length: 24 }, (_, hour) => (
                            <span key={hour} className="absolute right-2 text-[10px] text-[var(--pm-muted)]" style={{ top: hour * HOUR_HEIGHT + 3 }}>
                                {hour === 0 ? '12 AM' : hour < 12 ? `${hour} AM` : hour === 12 ? '12 PM' : `${hour - 12} PM`}
                            </span>
                        ))}
                    </div>

                    {dates.map(date => (
                        <div
                            key={date}
                            className={`relative min-w-0 flex-1 border-l border-[var(--pm-border)] ${selected === date ? 'block' : 'hidden md:block'} ${date === today ? 'bg-orange-50/30' : ''} ${(draggingTask || draggingBlock) && hover?.date === date ? 'bg-[var(--pm-accent)]/5' : ''} transition-colors duration-150`}
                            onDragOver={event => {
                                if (!draggingTask && !draggingBlock) return;

                                event.preventDefault();
                                event.dataTransfer.dropEffect = 'move';
                                setHover({ date, minutes: snapMinutes(event, draggingBlock?.grabMinutes) });
                            }}
                            onDragLeave={event => {
                                if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setHover(null);
                            }}
                            onDrop={event => drop(event, date)}
                        >
                            {Array.from({ length: 24 }, (_, hour) => (
                                <div key={hour} className="absolute inset-x-0 border-t border-[var(--pm-border)]" style={{ top: hour * HOUR_HEIGHT }}>
                                    <div className="absolute inset-x-0 border-t border-dashed border-[var(--pm-border)] opacity-40" style={{ top: HOUR_HEIGHT / 2 }} />
                                </div>
                            ))}

                            {dayLayout(blocks, date, timezone).map(({ session: block, start, end, lane, laneCount }) => {
                                const overlapping = conflicts.has(block.key);
                                const range = `${timeLabel(block.starts_at, timezone)} to ${timeLabel(block.ends_at, timezone)}`;
                                const palette = block.google
                                    ? 'border-[#d3d6d9] bg-[#f1f3f4] text-[var(--pm-text)]'
                                    : block.routine
                                    ? 'border-[#c3d5e6] bg-[#eaf1f8] text-[var(--pm-text)]'
                                    : block.completed ? 'border-[#e7c5b2] bg-slate-100 text-[var(--pm-muted)]' : 'border-[#e7c5b2] bg-[#f6e8df] text-[var(--pm-text)]';

                                return (
                                    <div
                                        key={block.key}
                                        role="button"
                                        tabIndex={0}
                                        draggable={!block.google}
                                        onClick={() => select(block)}
                                        onKeyDown={event => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                select(block);
                                            }
                                        }}
                                        onDragStart={event => {
                                            const rect = event.currentTarget.getBoundingClientRect();

                                            event.dataTransfer.setData('text/plain', block.key);
                                            event.dataTransfer.effectAllowed = 'move';
                                            setDraggingBlock({ block, grabMinutes: (event.clientY - rect.top) / HOUR_HEIGHT * 60 });
                                        }}
                                        onDragEnd={() => {
                                            setDraggingBlock(null);
                                            setHover(null);
                                        }}
                                        title={`${block.title} · ${range.replace(' to ', '–')}${block.routine ? ' · Repeats' : ''}${block.google ? ' · Google Calendar' : ''}${overlapping ? ' · Overlap' : ''}`}
                                        aria-label={`${block.title}, ${range}${block.routine ? ', repeats' : ''}${block.google ? ', from Google Calendar' : ''}${overlapping ? ', overlaps another event' : ''}`}
                                        className={`pm-calendar-session absolute ${block.google ? 'cursor-pointer' : 'cursor-grab'} overflow-hidden rounded-lg border px-1.5 py-1 text-left text-[10px] leading-tight ${draggingBlock?.block.key === block.key ? 'opacity-40' : ''} ${popping.has(signature(block)) ? 'pm-pop' : ''} ${palette} ${overlapping ? '!border-amber-600' : ''}`}
                                        style={{
                                            top: start / 60 * HOUR_HEIGHT,
                                            height: Math.max(18, (end - start) / 60 * HOUR_HEIGHT - 2),
                                            left: `calc(${lane / laneCount * 100}% + 2px)`,
                                            width: `calc(${100 / laneCount}% - 4px)`,
                                            borderLeftWidth: 3,
                                            borderLeftColor: overlapping ? '#b45309' : block.color ?? 'var(--pm-accent)',
                                        }}
                                    >
                                        <span className={`block truncate font-semibold ${block.completed ? 'line-through' : ''}`}>{block.routine && '↻ '}{block.google && <span className="mr-1 text-[var(--pm-muted)]">G</span>}{block.title}</span>
                                        {end - start >= 40 && <span className="mt-1 block truncate">{timeLabel(block.starts_at, timezone)}</span>}
                                        {end - start >= 75 && <span className="mt-1 block truncate opacity-70">{block.subtitle}</span>}
                                        {overlapping && end - start >= 55 && <span className="mt-1 block font-medium text-amber-800">Overlap</span>}
                                    </div>
                                );
                            })}

                            {deadlines.filter(task => task.due_has_time && dueDay(task, timezone) === date).map(task => (
                                <button
                                    key={`due-${task.id}`}
                                    type="button"
                                    onClick={() => onSelectDeadline(task)}
                                    title={`Due ${formatDue(task.due_at ?? '', true, timezone)} · ${task.title}`}
                                    aria-label={`Deadline: ${task.title}, ${formatDue(task.due_at ?? '', true, timezone)}${dueState(task, timezone) === 'overdue' ? ', overdue' : ''}`}
                                    className={`pm-deadline pm-deadline--${dueState(task, timezone)}`}
                                    style={{ top: dueMinutes(task, timezone) / 60 * HOUR_HEIGHT }}
                                >
                                    <span className="pm-deadline__label">{task.title}</span>
                                </button>
                            ))}

                            {(draggingTask || draggingBlock) && hover?.date === date && (
                                <div className="pm-drop-preview" style={{ top: hover.minutes / 60 * HOUR_HEIGHT, height: Math.max(18, previewMinutes / 60 * HOUR_HEIGHT - 2) }}>
                                    {previewTitle} · {clock(hover.minutes)}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--pm-border)] px-4 py-3 text-xs text-[var(--pm-muted)]">
                <span>
                    {sessions.length} work {sessions.length === 1 ? 'session' : 'sessions'} · {routineOccurrences.length} routine {routineOccurrences.length === 1 ? 'event' : 'events'} · {weekDeadlineCount} {weekDeadlineCount === 1 ? 'deadline' : 'deadlines'}{googleEvents.length > 0 && ` · ${googleEvents.length} from Google`} this week
                    {googleLoading && <span className="ml-2 animate-pulse">Loading Google events…</span>}
                </span>
                <span>{conflicts.size ? `${conflicts.size} overlapping` : 'No overlaps'} · {timezone.replaceAll('_', ' ')}</span>
            </div>
        </div>
    );
}
