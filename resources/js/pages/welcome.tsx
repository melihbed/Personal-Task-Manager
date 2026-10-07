import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../../resources/js/layouts/app-layout';
import GoogleEventDialog from '../components/google-event-dialog';
import ResponsibilityDialog from '../components/responsibility-dialog';
import RoutineDialog from '../components/routine-dialog';
import RoutineMoveDialog from '../components/routine-move-dialog';
import RoutineOccurrenceDialog from '../components/routine-occurrence-dialog';
import TasksCard from '../components/tasks/tasks-card';
import WeeklyCalendar from '../../../resources/js/components/weekly-calendar';
import ScheduleDialog from '../../../resources/js/components/schedule-dialog';
import { addDays, dateLabel, localToISO, overlaps, timeLabel, zonedParts, type GoogleEvent, type PlannerRoutine, type PlannerSession, type PlannerTask, type RoutineOccurrence } from '../lib/planner';

type Responsibility = { id: number; name: string; description: string | null; color: string | null };
type Props = { name: string; email: string; responsibilities: Responsibility[]; tasks: PlannerTask[]; sessions: PlannerSession[]; routines: PlannerRoutine[]; routineSessions: RoutineOccurrence[]; routinesToday: RoutineOccurrence[]; google?: { connected: boolean; needsReconnect: boolean }; googleEvents?: GoogleEvent[]; weekStart: string; timezone: string };

const clock24 = (value: number) => `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;

function SessionDialog({ session, timezone, onClose, onEdit }: { session: PlannerSession; timezone: string; onClose: () => void; onEdit: ()=> void;}) {
    const ref = useRef<HTMLDialogElement>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => { ref.current?.showModal(); }, []);
    function remove() {
        setSaving(true);
        router.delete(`/calendar-sessions/${session.id}`, { preserveScroll: true, onSuccess: onClose, onError: () => setError('Could not remove this session. Please try again.'), onFinish: () => setSaving(false) });
    }
    return <dialog ref={ref} className="pm-dialog" aria-labelledby="session-title" onCancel={e => { if (saving) e.preventDefault(); else onClose(); }} onClose={onClose}>
        <div className="flex items-start justify-between gap-4"><h2 id="session-title" className="text-xl font-medium break-words">{session.title}</h2><button type="button" disabled={saving} onClick={onClose} aria-label="Close session" className="pm-button pm-button--secondary pm-button--icon">×</button></div>
        <p className="mt-3 text-sm text-[var(--pm-muted)]">{session.responsibility_name}{session.completed ? ' · Task completed' : ''}</p>
        <p className="mt-5 text-sm">{new Intl.DateTimeFormat(undefined, { timeZone: timezone, weekday: 'long', month: 'short', day: 'numeric' }).format(new Date(session.starts_at))}</p>
        <p className="mt-1 font-medium">{timeLabel(session.starts_at, timezone)} – {timeLabel(session.ends_at, timezone)}</p>
        <p className="mt-2 text-xs text-[var(--pm-muted)]">{timezone.replaceAll('_', ' ')}</p>
        <p className="mt-6 text-sm text-[var(--pm-muted)]">Removing this session keeps the task. Use Plan to schedule another time.</p>
        {error && <p role="alert" className="mt-3 text-sm text-red-700">{error}</p>}
        <div className="pm-button-group mt-5">
            <button
                type="button"
                disabled={saving}
                onClick={onEdit}
                className="pm-button"
            >
                Edit time
            </button>

            <button
                type="button"
                disabled={saving}
                onClick={remove}
                className="pm-button pm-button--danger"
            >
                {saving ? 'Removing…' : 'Remove from calendar'}
            </button>
        </div>
    </dialog>;
}

export default function Welcome({
                                    name,
                                    responsibilities,
                                    tasks,
                                    sessions,
                                    routines = [],
                                    routineSessions = [],
                                    routinesToday = [],
                                    google,
                                    googleEvents,
                                    weekStart,
                                    timezone = 'America/New_York',
                                }: Props) {
    const [selectedTask, setSelectedTask] =
        useState<PlannerTask | null>(null);

    const [selectedSession, setSelectedSession] =
        useState<PlannerSession | null>(null);

    const [editingSession, setEditingSession] =
        useState<PlannerSession | null>(null);

    const editingTask = editingSession
        ? tasks.find(task => task.id === editingSession.task_id)
        : undefined;

    const [responsibilityDialogOpen, setResponsibilityDialogOpen] = useState(false);
    const [draggingTask, setDraggingTask] = useState<PlannerTask | null>(null);
    const [routineDialog, setRoutineDialog] = useState<{ id: number | null } | null>(null);
    const [selectedOccurrence, setSelectedOccurrence] = useState<RoutineOccurrence | null>(null);
    const [selectedGoogleEvent, setSelectedGoogleEvent] = useState<GoogleEvent | null>(null);
    const [routineMove, setRoutineMove] = useState<{ occurrence: RoutineOccurrence; startsAt: string; endsAt: string } | null>(null);
    const editingRoutine = routineDialog?.id != null ? routines.find(routine => routine.id === routineDialog.id) : undefined;
    const [planDraft, setPlanDraft] = useState<{ task: PlannerTask; session?: PlannerSession; initial: { date: string; startTime: string; endDate: string; endTime: string } } | null>(null);
    const deadlines = tasks.filter(task => task.due_at && !task.completed_at);
    const today = zonedParts(new Date(), timezone).date;
    const weekEnd = addDays(weekStart, 6);
    const scheduleDate = today >= weekStart && today <= weekEnd ? today : weekStart;
    const timezoneOptions = Array.from(
        new Set([
            timezone,
            'America/New_York',
            'Europe/Istanbul',
            'UTC',
        ]),
    );    /** Drop a task on the calendar: reserve its estimate (30 min by default) at the dropped time, or open the dialog if it clashes. */
    function planByDrop(date: string, minutes: number) {
        const task = draggingTask;
        setDraggingTask(null);
        if (!task) return;

        const clockTime = clock24;
        const duration = task.estimate_minutes ?? 30;
        let startsAt = '';
        let endsAt = '';
        let initial = { date, startTime: clockTime(minutes), endDate: date, endTime: clockTime(Math.min(minutes + duration, 1439)) };

        try {
            startsAt = localToISO(date, initial.startTime, timezone);
            endsAt = new Date(Date.parse(startsAt) + duration * 60000).toISOString();
            const end = zonedParts(new Date(endsAt), timezone);
            initial = { ...initial, endDate: end.date, endTime: end.time };
        } catch {
            startsAt = '';
        }

        if (!startsAt || sessions.some(session => overlaps(startsAt, endsAt, session.starts_at, session.ends_at))) {
            setPlanDraft({ task, initial });
            return;
        }

        router.post(`/tasks/${task.id}/calendar-sessions`, { starts_at: startsAt, ends_at: endsAt, allow_overlap: false }, {
            preserveScroll: true,
            onError: () => setPlanDraft({ task, initial }),
        });
    }
    /** Drag a scheduled session to a new day or time, keeping its length; open the dialog if it clashes or fails. */
    function moveSession(session: PlannerSession, date: string, minutes: number) {
        const task = tasks.find(candidate => candidate.id === session.task_id);
        const lengthMs = Date.parse(session.ends_at) - Date.parse(session.starts_at);
        let startsAt = '';
        let endsAt = '';
        let initial = { date, startTime: clock24(minutes), endDate: date, endTime: clock24(Math.min(minutes + lengthMs / 60000, 1439)) };

        try {
            startsAt = localToISO(date, initial.startTime, timezone);
            endsAt = new Date(Date.parse(startsAt) + lengthMs).toISOString();
            const end = zonedParts(new Date(endsAt), timezone);
            initial = { ...initial, endDate: end.date, endTime: end.time };
        } catch {
            startsAt = '';
        }

        if (startsAt && Date.parse(startsAt) === Date.parse(session.starts_at)) return;

        const review = () => { if (task) setPlanDraft({ task, session, initial }); };

        if (!startsAt || sessions.some(other => other.id !== session.id && overlaps(startsAt, endsAt, other.starts_at, other.ends_at))) {
            review();
            return;
        }

        router.patch(`/calendar-sessions/${session.id}`, { starts_at: startsAt, ends_at: endsAt, allow_overlap: false }, {
            preserveScroll: true,
            onError: review,
        });
    }
    /** A routine occurrence was dropped on a new day or time (its length is kept). Ask whether to move only that day or all of them. */
    function moveRoutine(occurrence: RoutineOccurrence, date: string, minutes: number) {
        const lengthMs = Date.parse(occurrence.ends_at) - Date.parse(occurrence.starts_at);

        try {
            const startsAt = localToISO(date, clock24(minutes), timezone);

            if (Date.parse(startsAt) === Date.parse(occurrence.starts_at)) return;

            setRoutineMove({ occurrence, startsAt, endsAt: new Date(Date.parse(startsAt) + lengthMs).toISOString() });
        } catch {
            setSelectedOccurrence(occurrence);
        }
    }
    function navigate(week: string | null, zone = timezone) {
        router.get('/', { ...(week ? { week } : {}), timezone: zone }, { preserveScroll: true });
    }
    return <AppLayout title="Dashboard">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 className="text-2xl font-medium tracking-tight">Hi {name}.</h2>
                <p className="mt-1 text-sm text-[var(--pm-muted)]">Choose what matters. Give it time this week.</p>
            </div>
            <label className="flex items-center gap-2 text-xs text-[var(--pm-muted)]">
                Timezone
                <select aria-label="Calendar timezone" value={timezone} onChange={e => navigate(weekStart, e.target.value)} className="max-w-52 rounded-lg border cursor-pointer border-[var(--pm-border)] bg-white px-3 py-2 text-[var(--pm-text)]">
            {timezoneOptions.map(zone =>
                    <option key={zone} value={zone}>{zone.replaceAll('_', ' ')}
                    </option>)}
                </select>
            </label>
        </div>
        <div className="grid items-start gap-6 xl:grid-cols-[340px_minmax(0,1fr)]">
            <TasksCard
                tasks={tasks}
                responsibilities={responsibilities}
                routines={routines}
                routinesToday={routinesToday}
                timezone={timezone}
                draggingTaskId={draggingTask?.id ?? null}
                onSchedule={setSelectedTask}
                onDragTask={setDraggingTask}
                onNewRoutine={() => setRoutineDialog({ id: null })}
                onNewResponsibility={() => setResponsibilityDialogOpen(true)}
                onEditRoutine={routine => setRoutineDialog({ id: routine.id })}
                onSelectOccurrence={setSelectedOccurrence}
            />
            <section className="min-w-0" aria-labelledby="week-title">{google?.needsReconnect && <p role="alert" className="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">Google Calendar needs to be connected again, so syncing is paused. <Link href="/integrations/google" className="pm-link">Fix it</Link></p>}<div className="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 id="week-title" className="text-lg font-medium">{dateLabel(weekStart, { month: 'short', day: 'numeric' })} – {dateLabel(weekEnd, { month: 'short', day: 'numeric', year: 'numeric' })}</h2><p className="mt-1 text-xs text-[var(--pm-muted)]">Monday–Sunday · Click a session to manage it</p></div><div className="pm-button-group"><button type="button" aria-label="Previous week" onClick={() => navigate(addDays(weekStart, -7))} className="pm-button pm-button--secondary pm-button--icon">‹</button><button type="button" onClick={() => navigate(null)} className="pm-button pm-button--secondary">Today</button><button type="button" aria-label="Next week" onClick={() => navigate(addDays(weekStart, 7))} className="pm-button pm-button--secondary pm-button--icon">›</button></div></div><WeeklyCalendar weekStart={weekStart} timezone={timezone} sessions={sessions} onSelect={setSelectedSession} deadlines={deadlines} onSelectDeadline={setSelectedTask} routineOccurrences={routineSessions} onSelectRoutine={setSelectedOccurrence} googleEvents={googleEvents ?? []} onSelectGoogle={setSelectedGoogleEvent} googleLoading={!!google?.connected && googleEvents === undefined} draggingTask={draggingTask} onDropTask={planByDrop} onMoveSession={moveSession} onMoveRoutine={moveRoutine} />{sessions.length === 0 && <p className="mt-3 text-sm text-[var(--pm-muted)]">Your week is open. Drag a task here, or choose Plan beside it, to reserve time.</p>}</section>
        </div>
        {routineDialog && (routineDialog.id === null || editingRoutine) && (
            <RoutineDialog
                key={`routine-${routineDialog.id ?? 'new'}`}
                routine={editingRoutine}
                responsibilities={responsibilities}
                timezone={timezone}
                onClose={() => setRoutineDialog(null)}
            />
        )}
        {selectedGoogleEvent && (
            <GoogleEventDialog
                key={selectedGoogleEvent.id}
                event={selectedGoogleEvent}
                responsibilities={responsibilities}
                timezone={timezone}
                onClose={() => setSelectedGoogleEvent(null)}
            />
        )}
        {routineMove && routines.some(routine => routine.id === routineMove.occurrence.routine_id) && (
            <RoutineMoveDialog
                key={`move-${routineMove.occurrence.routine_id}-${routineMove.occurrence.occurs_on}-${routineMove.startsAt}`}
                occurrence={routineMove.occurrence}
                routine={routines.find(routine => routine.id === routineMove.occurrence.routine_id) as PlannerRoutine}
                startsAt={routineMove.startsAt}
                endsAt={routineMove.endsAt}
                timezone={timezone}
                onClose={() => setRoutineMove(null)}
            />
        )}
        {selectedOccurrence && (
            <RoutineOccurrenceDialog
                key={`${selectedOccurrence.routine_id}-${selectedOccurrence.occurs_on}`}
                occurrence={routineSessions.concat(routinesToday).find(item => item.routine_id === selectedOccurrence.routine_id && item.occurs_on === selectedOccurrence.occurs_on) ?? selectedOccurrence}
                routine={routines.find(routine => routine.id === selectedOccurrence.routine_id)}
                timezone={timezone}
                onEditRoutine={() => { setRoutineDialog({ id: selectedOccurrence.routine_id }); setSelectedOccurrence(null); }}
                onClose={() => setSelectedOccurrence(null)}
            />
        )}
        {responsibilityDialogOpen && <ResponsibilityDialog existingCount={responsibilities.length} onClose={() => setResponsibilityDialogOpen(false)} />}
        {planDraft && <ScheduleDialog key={`plan-${planDraft.session?.id ?? 'new'}-${planDraft.task.id}-${planDraft.initial.date}-${planDraft.initial.startTime}`} task={planDraft.task} session={planDraft.session} date={planDraft.initial.date} initial={planDraft.initial} timezone={timezone} sessions={sessions} onClose={() => setPlanDraft(null)} />}
        {selectedTask && <ScheduleDialog key={selectedTask.id} task={selectedTask} date={scheduleDate} timezone={timezone} sessions={sessions} onClose={() => setSelectedTask(null)} />}
        {selectedSession && (
            <SessionDialog
                key={selectedSession.id}
                session={selectedSession}
                timezone={timezone}
                onClose={() => setSelectedSession(null)}
                onEdit={() => {
                    const task = tasks.find(
                        task => task.id === selectedSession.task_id,
                    );

                    if (!task) {
                        window.alert(
                            'This task is unavailable in the dashboard. It may belong to an archived responsibility.',
                        );
                        return;
                    }

                    setEditingSession(selectedSession);
                    setSelectedSession(null);
                }}
            />
        )}
        {editingSession && editingTask && (
            <ScheduleDialog
                key={`edit-${editingSession.id}`}
                task={editingTask}
                session={editingSession}
                date={scheduleDate}
                timezone={timezone}
                sessions={sessions}
                onClose={() => setEditingSession(null)}
            />
        )}
    </AppLayout>;
}
