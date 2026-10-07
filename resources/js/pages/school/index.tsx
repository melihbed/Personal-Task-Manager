import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useCompanion } from '../../components/companion/companion-context';
import SchoolAssignmentDialog from '../../components/school/school-assignment-dialog';
import SchoolRow from '../../components/school/school-row';
import Button from '../../components/ui/button';
import Popover from '../../components/ui/popover';
import AppLayout from '../../layouts/app-layout';
import { courseColor, courseTitle, groupAssignments, type SchoolAssignment, type SchoolGroupKey } from '../../lib/school';
import { useStoredState } from '../../lib/use-stored-state';

type Course = { id: number; name: string; course_code: string | null };
type Props = {
    state: 'not_configured' | 'not_connected' | 'needs_reconnect' | 'connected';
    lastSyncedAt: string | null;
    courses: Course[];
    assignments: SchoolAssignment[];
};
type Tab = 'todo' | 'done';

const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const isTab = (value: unknown): value is Tab => value === 'todo' || value === 'done';
const isString = (value: unknown): value is string => typeof value === 'string';
const tones: Partial<Record<SchoolGroupKey, string>> = { overdue: 'text-[var(--pm-overdue)]', today: 'text-[var(--pm-today)]' };

function Chevron({ open }: { open: boolean }) {
    return (
        <svg aria-hidden="true" className={`h-3.5 w-3.5 shrink-0 transition-transform duration-200 ${open ? 'rotate-90' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="m9 6 6 6-6 6" /></svg>
    );
}

function Dot({ color }: { color: string }) {
    return <span aria-hidden="true" className="size-2 shrink-0 rounded-full" style={{ background: color }} />;
}

function Empty({ state }: { state: Props['state'] }) {
    return (
        <div className="pm-card max-w-xl">
            <h2 className="text-lg font-medium">{state === 'needs_reconnect' ? 'Canvas needs reconnecting' : 'Connect Canvas to see your coursework'}</h2>
            <p className="mt-2 text-sm text-[var(--pm-muted)]">
                {state === 'needs_reconnect' ? 'Canvas no longer accepts your saved token, so this page has stopped updating.' : 'Upcoming and missing assignments appear here, and each one becomes a task in your planner.'}
            </p>
            <Link href="/integrations/canvas" className="pm-button mt-5">{state === 'needs_reconnect' ? 'Reconnect' : 'Connect Canvas'}</Link>
        </div>
    );
}

export default function School({ state, lastSyncedAt, courses, assignments }: Props) {
    const { celebrate } = useCompanion();
    const [tab, setTab] = useStoredState<Tab>('pm.school.tab', 'todo', isTab);
    const [collapsedKeys, setCollapsedKeys] = useStoredState<string>('pm.school.collapsed', 'later,undated', isString);
    const [courseId, setCourseId] = useState<number | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [leaving, setLeaving] = useState<Set<number>>(new Set());
    const [syncing, setSyncing] = useState(false);
    const now = useMemo(() => new Date(), []);
    const { open, done } = useMemo(() => groupAssignments(assignments, now, timezone, courseId), [assignments, now, courseId]);
    const collapsed = new Set(collapsedKeys.split(',').filter(Boolean));
    const openCount = open.reduce((total, group) => total + group.items.length, 0);
    const overdueCount = open.find(group => group.key === 'overdue')?.items.length ?? 0;
    const selected = assignments.find(item => item.id === selectedId) ?? null;
    const colorOf = (id: number) => courseColor(courses, id);
    const chosenCourse = courses.find(course => course.id === courseId) ?? null;

    function toggleSection(key: string) {
        const next = new Set(collapsed);

        if (next.has(key)) next.delete(key); else next.add(key);
        setCollapsedKeys([...next].join(','));
    }

    function toggle(item: SchoolAssignment) {
        if (item.task_id === null) return;

        const completing = !item.done;

        if (completing) setLeaving(current => new Set(current).add(item.id));

        router.patch(`/tasks/${item.task_id}/completion`, { completed: completing }, {
            preserveScroll: true,
            onSuccess: () => { if (completing) celebrate(); },
            onFinish: () => setLeaving(current => { const next = new Set(current); next.delete(item.id); return next; }),
        });
    }

    const row = (item: SchoolAssignment) => (
        <SchoolRow key={item.id} item={item} timezone={timezone} color={colorOf(item.course_id)} leaving={leaving.has(item.id)} onOpen={setSelectedId.bind(null, item.id)} onToggle={toggle} />
    );

    return (
        <AppLayout title="School">
            {state !== 'connected' ? <Empty state={state} /> : (
                <section aria-labelledby="school-title" className="max-w-3xl rounded-3xl border border-[var(--pm-border)] bg-white">
                    <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-5">
                        <div>
                            <h2 id="school-title" className="font-semibold">Coursework</h2>
                            <p className="mt-1 text-xs text-[var(--pm-muted)]">
                                {openCount} to do{overdueCount > 0 && <span className="ml-2 font-medium text-[var(--pm-overdue)]">{overdueCount} overdue</span>}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="hidden text-xs text-[var(--pm-muted)] sm:inline">
                                {lastSyncedAt ? `Synced ${new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(lastSyncedAt))}` : 'Not synced yet'}
                            </span>
                            <Button type="button" variant="secondary" size="small" loading={syncing} loadingLabel="Syncing" onClick={() => { setSyncing(true); router.post('/integrations/canvas/sync', {}, { preserveScroll: true, onFinish: () => setSyncing(false) }); }}>
                                Sync now
                            </Button>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 px-4 pt-4">
                        <div
                            role="tablist"
                            aria-label="To do or done"
                            className="pm-tabs min-w-52 flex-1 sm:max-w-72"
                            onKeyDown={(event) => { if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') setTab(tab === 'todo' ? 'done' : 'todo'); }}
                        >
                            {(['todo', 'done'] as Tab[]).map(name => (
                                <button key={name} type="button" role="tab" id={`school-tab-${name}`} aria-selected={tab === name} aria-controls={`school-panel-${name}`} tabIndex={tab === name ? 0 : -1} onClick={() => setTab(name)} className="pm-tab">
                                    {name === 'todo' ? `To do ${openCount}` : `Done ${done.length}`}
                                </button>
                            ))}
                        </div>
                        <Popover
                            ariaLabel="Filter by course"
                            active={chosenCourse !== null}
                            label={<>{chosenCourse && <Dot color={colorOf(chosenCourse.id)} />}<span className="max-w-44 truncate">{chosenCourse ? courseTitle(chosenCourse.name) : 'All courses'}</span></>}
                        >
                            {close => (
                                <div className="max-h-72 overflow-y-auto">
                                    <button type="button" className="pm-popover-item flex items-center gap-2" onClick={() => { setCourseId(null); close(); }}>
                                        <span className="flex-1">All courses</span>{courseId === null && <span aria-hidden="true">✓</span>}
                                    </button>
                                    {courses.map(course => (
                                        <button key={course.id} type="button" className="pm-popover-item flex items-center gap-2" onClick={() => { setCourseId(course.id); close(); }}>
                                            <Dot color={colorOf(course.id)} />
                                            <span className="min-w-0 flex-1 truncate" title={course.name}>{courseTitle(course.name)}</span>
                                            {courseId === course.id && <span aria-hidden="true">✓</span>}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </Popover>
                    </div>

                    {tab === 'todo' ? (
                        <div role="tabpanel" id="school-panel-todo" aria-labelledby="school-tab-todo" className="pm-tab-panel px-2 pt-2 pb-3">
                            {open.length === 0 && (
                                <div className="px-4 py-10">
                                    <p className="text-sm font-medium">All caught up</p>
                                    <p className="mt-1 max-w-xs text-xs text-[var(--pm-muted)]">{courses.length === 0 ? 'No courses are tracked yet. Choose them in the Canvas settings.' : 'Nothing is waiting in these courses.'}</p>
                                </div>
                            )}
                            {open.map(group => {
                                const isOpen = !collapsed.has(group.key);

                                return (
                                    <div key={group.key} className="mt-1">
                                        <button type="button" onClick={() => toggleSection(group.key)} aria-expanded={isOpen} className="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-left">
                                            <Chevron open={isOpen} />
                                            <span className={`text-xs font-semibold tracking-wide uppercase ${tones[group.key] ?? 'text-[var(--pm-muted)]'}`}>{group.title}</span>
                                            <span className="ml-auto text-xs text-[var(--pm-muted)]">{group.items.length}</span>
                                        </button>
                                        <div className="pm-collapse" data-open={isOpen}>
                                            <div><ul>{group.items.map(row)}</ul></div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div role="tabpanel" id="school-panel-done" aria-labelledby="school-tab-done" className="pm-tab-panel px-2 pt-2 pb-3">
                            {done.length === 0
                                ? <div className="px-4 py-10"><p className="text-sm font-medium">Nothing finished lately</p><p className="mt-1 max-w-xs text-xs text-[var(--pm-muted)]">Work you submit or tick off appears here for two weeks.</p></div>
                                : <ul>{done.map(row)}</ul>}
                        </div>
                    )}
                </section>
            )}

            {selected && <SchoolAssignmentDialog key={selected.id} item={selected} timezone={timezone} color={colorOf(selected.course_id)} onToggle={toggle} onClose={() => setSelectedId(null)} />}
        </AppLayout>
    );
}
