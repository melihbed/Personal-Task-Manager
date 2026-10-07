import { useEffect, useState } from 'react';
import { usePomodoro } from '../../components/pomodoro/pomodoro-context';
import Button from '../../components/ui/button';
import Disclosure from '../../components/ui/disclosure';
import AppLayout from '../../layouts/app-layout';
import { breakLabel, formatClock, kindLabels, loadStats, type Settings, type Stats, type TimerKind } from '../../lib/pomodoro';

type Props = { tasks: { id: number; title: string }[]; initialTaskId: number | null };

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const RING = 2 * Math.PI * 104;

function Ring({ fraction, kind }: { fraction: number; kind: TimerKind | null }) {
    const color = kind === null || kind === 'focus' ? 'var(--pm-accent)' : 'var(--pm-blue)';

    return (
        <svg aria-hidden="true" viewBox="0 0 240 240" className="absolute inset-0 size-full -rotate-90">
            <circle cx="120" cy="120" r="104" fill="none" stroke="var(--pm-background)" strokeWidth="10" />
            <circle cx="120" cy="120" r="104" fill="none" stroke={color} strokeWidth="10" strokeLinecap="round" strokeDasharray={RING} strokeDashoffset={RING * (1 - fraction)} style={{ transition: 'stroke-dashoffset 500ms linear, stroke 200ms ease' }} />
        </svg>
    );
}

function Dots({ done, of }: { done: number; of: number }) {
    return (
        <div role="img" aria-label={`${done} of ${of} rounds done in this set`} className="flex items-center gap-2">
            {Array.from({ length: of }, (_, index) => <span key={index} className={`size-2.5 rounded-full transition-colors duration-300 ${index < done ? 'bg-[var(--pm-accent)]' : 'bg-[var(--pm-border)]'}`} />)}
        </div>
    );
}

function TimerCard({ tasks, initialTaskId }: Props) {
    const timer = usePomodoro();
    const [taskId, setTaskId] = useState<number | null>(tasks.some(task => task.id === initialTaskId) ? initialTaskId : null);
    const state = timer.state;
    const active = state?.active ?? null;
    const last = state?.last_completed ?? null;

    if (state === null) {
        return <section className="pm-card" aria-label="Timer"><div role="status" aria-label="Loading the timer" className="mx-auto size-60 animate-pulse rounded-full bg-[var(--pm-background)]" /></section>;
    }

    // What the card is offering: the running timer, the break that follows a round, the next round after a break, or a fresh start.
    const afterFocus = active === null && last?.kind === 'focus';
    const afterBreak = active === null && (last?.kind === 'short_break' || last?.kind === 'long_break');
    const phase: TimerKind | null = active?.kind ?? (afterFocus ? state.cycle.next_break : null);
    const total = active ? active.planned_seconds : phase === 'long_break' ? state.settings.long_break_minutes * 60 : phase === 'short_break' ? state.settings.short_break_minutes * 60 : state.settings.focus_minutes * 60;
    const seconds = timer.secondsLeft ?? total;
    const heading = active ? kindLabels[active.kind] : afterFocus ? 'Round done' : afterBreak ? 'Break over' : 'Ready to focus';
    const hint = active
        ? active.task_title ?? (active.kind === 'focus' ? 'No task chosen' : 'Step away from the screen')
        : afterFocus ? `Take a ${breakLabel(state)}.` : afterBreak ? 'Ready for the next round?' : `${state.settings.focus_minutes} minutes, then a break.`;

    return (
        <section className="pm-card" aria-labelledby="timer-title">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id="timer-title" className="text-lg font-medium">{heading}</h2>
                <Dots done={state.cycle.done} of={state.cycle.of} />
            </div>

            <div className="relative mx-auto mt-6 size-60 sm:size-72">
                <Ring fraction={total > 0 ? seconds / total : 0} kind={phase} />
                <div className="absolute inset-0 flex flex-col items-center justify-center px-10 text-center">
                    <p role="timer" aria-label={`${formatClock(seconds)} left`} className="text-5xl font-medium tabular-nums sm:text-6xl">{formatClock(seconds)}</p>
                    <p className="mt-2 line-clamp-2 text-sm text-[var(--pm-muted)]">{hint}</p>
                    {active?.status === 'paused' && <span className="pm-badge pm-badge--warn mt-3">Paused</span>}
                </div>
            </div>

            {active === null && (afterFocus ? null : (
                <div className="mx-auto mt-6 max-w-sm">
                    <label htmlFor="focus-task" className="text-sm font-medium">Working on</label>
                    <select id="focus-task" value={taskId ?? ''} onChange={(event) => setTaskId(event.target.value === '' ? null : Number(event.target.value))} className="pm-input cursor-pointer">
                        <option value="">No task</option>
                        {tasks.map(task => <option key={task.id} value={task.id}>{task.title}</option>)}
                    </select>
                </div>
            ))}

            <div className="pm-button-group mt-6 justify-center">
                {active === null && afterFocus && (
                    <>
                        <Button type="button" loading={timer.busy} loadingLabel="Starting" onClick={() => void timer.start(state.cycle.next_break)}>Start {state.cycle.next_break === 'long_break' ? 'long break' : 'break'}</Button>
                        <Button type="button" variant="secondary" disabled={timer.busy} onClick={() => void timer.start('focus', taskId)}>Skip break, next round</Button>
                    </>
                )}
                {active === null && !afterFocus && (
                    <Button type="button" loading={timer.busy} loadingLabel="Starting" onClick={() => void timer.start('focus', taskId)}>{afterBreak ? 'Start next round' : 'Start focus'}</Button>
                )}
                {active?.status === 'running' && <Button type="button" variant="secondary" loading={timer.busy} loadingLabel="Pausing" onClick={() => void timer.pause()}>Pause</Button>}
                {active?.status === 'paused' && <Button type="button" loading={timer.busy} loadingLabel="Resuming" onClick={() => void timer.resume()}>Resume</Button>}
                {active && <Button type="button" variant="danger" disabled={timer.busy} onClick={() => void timer.stop()}>{active.kind === 'focus' ? 'Stop' : 'Skip break'}</Button>}
            </div>

            {active?.kind === 'focus' && <p className="mt-4 text-center text-xs text-[var(--pm-muted)]">Stopping early does not count this round.</p>}
            {timer.error && <p role="alert" className="mt-4 text-center text-sm text-red-700">{timer.error}</p>}
        </section>
    );
}

function Bars({ week }: { week: Stats['week'] }) {
    const most = Math.max(60, ...week.map(day => day.minutes));

    return (
        <ol aria-label="Focus minutes, last 7 days" className="mt-4 flex h-28 items-end gap-2">
            {week.map((day, index) => {
                const label = new Intl.DateTimeFormat(undefined, { weekday: 'narrow', timeZone: 'UTC' }).format(new Date(`${day.date}T12:00:00Z`));
                const long = new Intl.DateTimeFormat(undefined, { weekday: 'long', timeZone: 'UTC' }).format(new Date(`${day.date}T12:00:00Z`));

                return (
                    <li key={day.date} className="flex h-full flex-1 flex-col items-center justify-end gap-1.5" title={`${long}: ${day.minutes} minutes, ${day.rounds} ${day.rounds === 1 ? 'round' : 'rounds'}`}>
                        <span className="sr-only">{long}: {day.minutes} minutes</span>
                        <span aria-hidden="true" className={`w-full rounded-md ${index === week.length - 1 ? 'bg-[var(--pm-accent)]' : 'bg-[var(--pm-blue)]/70'}`} style={{ height: `${Math.max(day.minutes > 0 ? 6 : 3, (day.minutes / most) * 80)}px`, opacity: day.minutes > 0 ? 1 : 0.35 }} />
                        <span aria-hidden="true" className="text-[11px] text-[var(--pm-muted)]">{label}</span>
                    </li>
                );
            })}
        </ol>
    );
}

function Tile({ label, value, note }: { label: string; value: string; note?: string }) {
    return (
        <div className="rounded-2xl bg-[var(--pm-background)] px-4 py-3">
            <p className="text-xs text-[var(--pm-muted)]">{label}</p>
            <p className="mt-1 text-xl font-medium tabular-nums">{value}</p>
            {note && <p className="text-[11px] text-[var(--pm-muted)]">{note}</p>}
        </div>
    );
}

function StatsCard() {
    const { state } = usePomodoro();
    const [stats, setStats] = useState<Stats | null>(null);
    const [failed, setFailed] = useState(false);
    const activeId = state?.active?.id ?? null;
    const done = state?.cycle.done ?? 0;

    // Load on arrival, and again whenever a timer starts or ends, so the numbers are never stale.
    useEffect(() => {
        if (state === null) return;

        loadStats(timezone).then((next) => { setStats(next); setFailed(false); }).catch(() => setFailed(true));
    }, [state === null, activeId, done]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <section className="pm-card" aria-labelledby="stats-title">
            <h2 id="stats-title" className="text-lg font-medium">Your focus</h2>
            {failed && <p role="alert" className="mt-3 text-sm text-red-700">Could not load your stats.</p>}
            {stats === null && !failed && <div role="status" aria-label="Loading your stats" className="mt-4 h-32 animate-pulse rounded-2xl bg-[var(--pm-background)]" />}
            {stats !== null && (
                <>
                    <div className="mt-4 grid grid-cols-3 gap-3">
                        <Tile label="Today" value={`${stats.today.rounds}`} note={`${stats.today.rounds === 1 ? 'round' : 'rounds'}, ${stats.today.minutes} min`} />
                        <Tile label="Streak" value={`${stats.streak}`} note={stats.streak === 1 ? 'day' : 'days'} />
                        <Tile label="All time" value={`${stats.total_rounds}`} note={stats.total_rounds === 1 ? 'round' : 'rounds'} />
                    </div>
                    <Bars week={stats.week} />
                    <h3 className="mt-6 text-sm font-medium">Recent rounds</h3>
                    {stats.recent.length === 0 ? <p className="mt-2 text-sm text-[var(--pm-muted)]">Finish a round and it shows up here.</p> : (
                        <ul className="mt-2 divide-y divide-[var(--pm-border)]">
                            {stats.recent.map(round => (
                                <li key={round.id} className="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                                    <span className={`min-w-0 truncate text-sm ${round.task_title ? '' : 'text-[var(--pm-muted)]'}`}>{round.task_title ?? 'No task'}</span>
                                    <span className="shrink-0 text-xs text-[var(--pm-muted)]">{round.minutes} min, {new Intl.DateTimeFormat(undefined, { weekday: 'short', hour: 'numeric', minute: '2-digit' }).format(new Date(round.ended_at))}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </section>
    );
}

const fields: { key: keyof Omit<Settings, 'sound_enabled'>; label: string; min: number; max: number; unit: string }[] = [
    { key: 'focus_minutes', label: 'Focus', min: 5, max: 180, unit: 'min' },
    { key: 'short_break_minutes', label: 'Break', min: 1, max: 60, unit: 'min' },
    { key: 'long_break_minutes', label: 'Long break', min: 1, max: 120, unit: 'min' },
    { key: 'rounds_before_long', label: 'Rounds before a long break', min: 2, max: 8, unit: 'rounds' },
];

function SettingsCard() {
    const timer = usePomodoro();
    const saved = timer.state?.settings;
    const [draft, setDraft] = useState<Settings | null>(null);
    const [message, setMessage] = useState('');
    const values = draft ?? saved;

    if (!saved || !values) return null;

    const changed = JSON.stringify(values) !== JSON.stringify(saved);

    return (
        <section className="pm-card">
            <Disclosure title="Rhythm and sound">
                <form
                    onSubmit={(event) => { event.preventDefault(); setMessage(''); void timer.updateSettings(values).then((ok) => { if (ok) { setDraft(null); setMessage('Saved. It applies from the next round.'); } }); }}
                    className="space-y-4"
                >
                    <div className="grid grid-cols-2 gap-4">
                        {fields.map(field => (
                            <div key={field.key} className={field.key === 'rounds_before_long' ? 'col-span-2' : ''}>
                                <label htmlFor={`setting-${field.key}`} className="text-sm font-medium">{field.label}</label>
                                <div className="flex items-center gap-2">
                                    <input id={`setting-${field.key}`} type="number" inputMode="numeric" min={field.min} max={field.max} value={values[field.key]} onChange={(event) => setDraft({ ...values, [field.key]: Number(event.target.value) })} className="pm-input w-24" />
                                    <span className="mt-1.5 text-xs text-[var(--pm-muted)]">{field.unit}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                    <label className="flex cursor-pointer items-center gap-3 text-sm">
                        <input type="checkbox" checked={values.sound_enabled} onChange={(event) => setDraft({ ...values, sound_enabled: event.target.checked })} className="size-4 accent-[var(--pm-text)]" />
                        Play a soft chime when a round or break ends
                    </label>
                    <div className="pm-button-group">
                        <Button type="submit" loading={timer.busy} loadingLabel="Saving" disabled={!changed}>Save</Button>
                        {changed && <Button type="button" variant="secondary" onClick={() => setDraft(null)}>Undo</Button>}
                    </div>
                    {message && <p role="status" className="text-sm text-[var(--pm-muted)]">{message}</p>}
                    {timer.error && <p role="alert" className="text-sm text-red-700">{timer.error}</p>}
                </form>
            </Disclosure>
        </section>
    );
}

export default function Focus({ tasks, initialTaskId }: Props) {
    return (
        <AppLayout title="Focus">
            <div className="grid max-w-5xl items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,380px)]">
                <TimerCard tasks={tasks} initialTaskId={initialTaskId} />
                <div className="space-y-6">
                    <StatsCard />
                    <SettingsCard />
                </div>
            </div>
        </AppLayout>
    );
}
