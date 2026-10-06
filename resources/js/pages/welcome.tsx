import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import AppLayout from '../../../resources/js/layouts/app-layout';
import TaskGroup from '../../../resources/js/components/task-group';
import WeeklyCalendar from '../../../resources/js/components/weekly-calendar';
import ScheduleDialog from '../../../resources/js/components/schedule-dialog';
import { addDays, dateLabel, timeLabel, zonedParts, type PlannerSession, type PlannerTask } from '../lib/planner';

type Responsibility = { id: number; name: string; description: string | null; color: string | null };
type Props = { name: string; email: string; responsibilities: Responsibility[]; tasks: PlannerTask[]; sessions: PlannerSession[]; weekStart: string; timezone: string };

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

    const [addOpen, setAddOpen] = useState(false);
    const form = useForm({ name: '', description: '' });
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
    );    function navigate(week: string | null, zone = timezone) {
        router.get('/', { ...(week ? { week } : {}), timezone: zone }, { preserveScroll: true });
    }
    function createResponsibility(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/responsibilities', { preserveScroll: true, onSuccess: () => { form.reset(); setAddOpen(false); } });
    }
    return <AppLayout title="Dashboard">
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-2xl font-medium tracking-tight">Hi {name}.</h2><p className="mt-1 text-sm text-[var(--pm-muted)]">Choose what matters. Give it time this week.</p></div><label className="flex items-center gap-2 text-xs text-[var(--pm-muted)]">Timezone<select aria-label="Calendar timezone" value={timezone} onChange={e => navigate(weekStart, e.target.value)} className="max-w-52 rounded-lg border border-[var(--pm-border)] bg-white px-3 py-2 text-[var(--pm-text)]">{timezoneOptions.map(zone => <option key={zone} value={zone}>{zone.replaceAll('_', ' ')}</option>)}</select></label></div>
        <div className="grid items-start gap-6 xl:grid-cols-[340px_minmax(0,1fr)]">
            <section aria-labelledby="tasks-title" className="overflow-hidden rounded-3xl border border-[var(--pm-border)] bg-white">
                <div className="flex items-center justify-between gap-3 border-b border-[var(--pm-border)] px-5 py-5"><div><h2 id="tasks-title" className="font-medium">Tasks to plan</h2><p className="mt-1 text-xs text-[var(--pm-muted)]">{tasks.filter(task => !task.completed_at).length} unfinished · Plan adds a work session</p></div><button type="button" onClick={() => setAddOpen(!addOpen)} aria-expanded={addOpen} aria-controls="add-responsibility" className="pm-button pm-button--secondary pm-button--small">+ Group</button></div>
                {addOpen && <form id="add-responsibility" onSubmit={createResponsibility} className="space-y-3 border-b border-[var(--pm-border)] bg-[var(--pm-background)]/40 p-4"><label className="block text-xs font-medium" htmlFor="group-name">Responsibility name</label><input autoFocus id="group-name" required maxLength={255} className="pm-input" value={form.data.name} onChange={e => form.setData('name', e.target.value)} placeholder="Capstone, UAMA…" /><label className="block text-xs" htmlFor="group-description">Description (optional)</label><textarea id="group-description" maxLength={5000} rows={2} className="pm-input" value={form.data.description} onChange={e => form.setData('description', e.target.value)} />{Object.values(form.errors).map((error, i) => <p key={i} role="alert" className="text-xs text-red-700">{error}</p>)}<button disabled={form.processing} className="pm-button pm-button--small" type="submit">{form.processing ? 'Saving…' : 'Add responsibility'}</button></form>}
                <div className="max-h-[720px] overflow-y-auto"><TaskGroup id={null} name="Inbox" color={null} tasks={tasks.filter(task => task.responsibility_id === null)} timezone={timezone} onSchedule={setSelectedTask} />{responsibilities.map(group => <TaskGroup key={group.id} id={group.id} name={group.name} color={group.color} tasks={tasks.filter(task => task.responsibility_id === group.id)} timezone={timezone} onSchedule={setSelectedTask} />)}</div>
            </section>
            <section className="min-w-0" aria-labelledby="week-title"><div className="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 id="week-title" className="text-lg font-medium">{dateLabel(weekStart, { month: 'short', day: 'numeric' })} – {dateLabel(weekEnd, { month: 'short', day: 'numeric', year: 'numeric' })}</h2><p className="mt-1 text-xs text-[var(--pm-muted)]">Monday–Sunday · Click a session to manage it</p></div><div className="pm-button-group"><button type="button" aria-label="Previous week" onClick={() => navigate(addDays(weekStart, -7))} className="pm-button pm-button--secondary pm-button--icon">‹</button><button type="button" onClick={() => navigate(null)} className="pm-button pm-button--secondary">Today</button><button type="button" aria-label="Next week" onClick={() => navigate(addDays(weekStart, 7))} className="pm-button pm-button--secondary pm-button--icon">›</button></div></div><WeeklyCalendar weekStart={weekStart} timezone={timezone} sessions={sessions} onSelect={setSelectedSession} />{sessions.length === 0 && <p className="mt-3 text-sm text-[var(--pm-muted)]">Your week is open. Choose Plan beside a task to reserve time.</p>}</section>
        </div>
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
