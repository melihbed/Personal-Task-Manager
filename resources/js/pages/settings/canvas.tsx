import { router, useForm, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import Button from '../../components/ui/button';
import ConfirmDialog from '../../components/ui/confirm-dialog';
import Disclosure from '../../components/ui/disclosure';
import Field from '../../components/ui/field';
import AppLayout from '../../layouts/app-layout';
import { integrationStates, type IntegrationState } from '../../lib/integrations';

type Course = { id: number; name: string; course_code: string | null; term: string | null; tracked: boolean; assignments_count: number };
type Account = { base_url: string; name: string | null; needs_reconnect: boolean; last_synced_at: string | null; last_error: string | null };
type Props = { state: IntegrationState; account: Account | null; courses: Course[]; tasksCreated: number };

const icon = '/images/integrations/canvas.svg';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="pm-card">
            <h3 className="text-lg font-medium">{title}</h3>
            <div className="mt-4">{children}</div>
        </section>
    );
}

function Badge({ variant, children }: { variant: '' | 'ok' | 'warn'; children: ReactNode }) {
    return <span className={`pm-badge ${variant ? `pm-badge--${variant}` : ''}`}>{children}</span>;
}

function lastSynced(iso: string | null): string {
    return iso ? new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(iso)) : 'Not yet';
}

function StatusSummary({ state, account, tasksCreated }: { state: IntegrationState; account: Account | null; tasksCreated: number }) {
    const connection = integrationStates[state === 'not_configured' ? 'not_connected' : state];
    const rows: { label: string; badge: ReactNode; note?: string }[] = [
        { label: 'Connection', badge: <Badge variant={connection.variant}>{connection.label}</Badge>, note: account?.name ?? undefined },
        { label: 'Last synced', badge: <Badge variant={account?.last_error ? 'warn' : account?.last_synced_at ? 'ok' : ''}>{lastSynced(account?.last_synced_at ?? null)}</Badge> },
        { label: 'Tasks from Canvas', badge: <Badge variant="">{tasksCreated}</Badge> },
    ];

    return (
        <section className="pm-card" aria-label="Status">
            <div className="mb-4 flex items-center gap-3 border-b border-[var(--pm-border)] pb-4">
                <img src={icon} alt="" width={36} height={36} className="size-9 shrink-0" />
                <div>
                    <p className="font-medium">Canvas</p>
                    <p className="text-xs text-[var(--pm-muted)]">Your coursework, read-only</p>
                </div>
            </div>
            <dl className="divide-y divide-[var(--pm-border)]">
                {rows.map(row => (
                    <div key={row.label} className="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                        <dt className="text-sm">{row.label}</dt>
                        <dd className="flex items-center gap-3">
                            {row.note && <span className="text-xs text-[var(--pm-muted)]">{row.note}</span>}
                            {row.badge}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

/** Paste the address and token. Used to connect, and to replace a token that stopped working. */
function ConnectForm({ reconnecting, defaultAddress }: { reconnecting: boolean; defaultAddress: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({ address: defaultAddress, token: '' });

    return (
        <Section title={reconnecting ? 'Paste a new token' : 'Connect Canvas'}>
            <p className="text-sm text-[var(--pm-muted)]">
                The app reads your courses and assignments with a personal access token. It can only read: it never submits or changes anything in Canvas.
                The token is stored encrypted, and you can disconnect at any time.
            </p>
            <form onSubmit={(event) => { event.preventDefault(); post('/integrations/canvas', { preserveScroll: true, onSuccess: () => reset('token') }); }} className="mt-5 space-y-4">
                <Field id="canvas-address" label="Canvas address" placeholder="njit.instructure.com" autoComplete="off" value={data.address} onChange={(event) => setData('address', event.target.value)} error={errors.address} />
                <Field id="canvas-token" label="Access token" type="password" autoComplete="off" spellCheck={false} value={data.token} onChange={(event) => setData('token', event.target.value)} error={errors.token} />
                <Button type="submit" loading={processing} loadingLabel="Connecting" disabled={data.address.trim() === '' || data.token.trim() === ''}>
                    {reconnecting ? 'Reconnect' : 'Connect Canvas'}
                </Button>
            </form>
            <div className="mt-5">
                <Disclosure title="How do I get a token?">
                    <ol className="list-decimal space-y-2 pl-5">
                        <li>Sign in to Canvas and open <strong>Account → Settings</strong>.</li>
                        <li>Under <strong>Approved Integrations</strong>, choose <strong>New Access Token</strong>.</li>
                        <li>Name it “Personal manager” and set an expiry about four months away.</li>
                        <li>Copy the token right away, since Canvas shows it only once, and paste it above. Don’t share it with anyone, including in chat.</li>
                    </ol>
                </Disclosure>
            </div>
        </Section>
    );
}

function Courses({ courses }: { courses: Course[] }) {
    const [chosen, setChosen] = useState<number[]>(courses.filter(course => course.tracked).map(course => course.id));
    const [saving, setSaving] = useState(false);
    const saved = courses.filter(course => course.tracked).map(course => course.id);
    const changed = [...chosen].sort().join() !== [...saved].sort().join();

    if (courses.length === 0) {
        return <Section title="Courses"><p className="text-sm text-[var(--pm-muted)]">No current courses found yet. Use Sync now, or check that your token belongs to a student account.</p></Section>;
    }

    return (
        <Section title="Courses to track">
            <p className="text-sm text-[var(--pm-muted)]">Each assignment in a tracked course gets a task in your School responsibility. Courses from past terms start unticked. Unticking a course removes the tasks it made, except ones you edited, planned or completed.</p>
            <form onSubmit={(event) => { event.preventDefault(); setSaving(true); router.patch('/integrations/canvas', { tracked_course_ids: chosen }, { preserveScroll: true, onFinish: () => setSaving(false) }); }} className="mt-4">
                <div className="space-y-3">
                    {courses.map(course => (
                        <label key={course.id} className="flex cursor-pointer items-start gap-3">
                            <input
                                type="checkbox"
                                checked={chosen.includes(course.id)}
                                onChange={() => setChosen(current => current.includes(course.id) ? current.filter(id => id !== course.id) : [...current, course.id])}
                                className="mt-1 size-4 accent-[var(--pm-text)]"
                            />
                            <span className="text-sm">
                                {course.name}
                                <span className="block text-xs text-[var(--pm-muted)]">{[course.course_code, course.term, `${course.assignments_count} ${course.assignments_count === 1 ? 'assignment' : 'assignments'}`].filter(Boolean).join(' · ')}</span>
                            </span>
                        </label>
                    ))}
                </div>
                <Button type="submit" className="mt-5" loading={saving} loadingLabel="Saving" disabled={!changed}>Save courses</Button>
            </form>
        </Section>
    );
}

function Connected({ account, courses }: { account: Account; courses: Course[] }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [syncing, setSyncing] = useState(false);

    return (
        <>
            {account.needs_reconnect
                ? <div role="alert" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">Canvas no longer accepts the saved token, so syncing is paused. Paste a new one below.</div>
                : account.last_error && <div role="alert" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">The last sync failed: {account.last_error} It will try again automatically.</div>}

            {account.needs_reconnect && <ConnectForm reconnecting defaultAddress={account.base_url.replace('https://', '')} />}
            {!account.needs_reconnect && <Courses key={courses.map(course => `${course.id}${course.tracked}`).join()} courses={courses} />}

            {!account.needs_reconnect && (
                <Section title="Sync">
                    <p className="text-sm text-[var(--pm-muted)]">Canvas is read about every 30 minutes. Use this if something looks out of step.</p>
                    <div className="pm-button-group mt-4">
                        <Button type="button" variant="secondary" loading={syncing} loadingLabel="Syncing" onClick={() => { setSyncing(true); router.post('/integrations/canvas/sync', {}, { preserveScroll: true, onFinish: () => setSyncing(false) }); }}>
                            Sync now
                        </Button>
                    </div>
                </Section>
            )}

            <Section title="Connection">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm">
                        Connected to <strong>{account.base_url.replace('https://', '')}</strong>
                        <span className="mt-1 block text-xs text-[var(--pm-muted)]">Tasks already made stay when you disconnect.</span>
                    </p>
                    <Button type="button" variant="danger" size="small" onClick={() => setConfirming(true)}>Disconnect</Button>
                </div>
            </Section>

            {confirming && (
                <ConfirmDialog
                    title="Disconnect Canvas?"
                    description="The app forgets your token and stops syncing. Tasks it already made stay in your planner, and they are not made twice if you connect again."
                    confirmLabel="Disconnect"
                    busy={busy}
                    onConfirm={() => { setBusy(true); router.delete('/integrations/canvas', { onFinish: () => { setBusy(false); setConfirming(false); } }); }}
                    onCancel={() => setConfirming(false)}
                />
            )}
        </>
    );
}

export default function Canvas({ state, account, courses, tasksCreated }: Props) {
    const { status } = usePage<{ status: string | null }>().props;

    return (
        <AppLayout title="Canvas" backHref="/integrations">
            <div className="max-w-2xl space-y-5">
                {status && <p role="status" className="rounded-xl bg-[var(--pm-surface)] px-4 py-3 text-sm shadow-sm">{status}</p>}
                <StatusSummary state={state} account={account} tasksCreated={tasksCreated} />
                {account === null ? <ConnectForm reconnecting={false} defaultAddress="njit.instructure.com" /> : <Connected account={account} courses={courses} />}
                <p className="px-1 text-xs text-[var(--pm-muted)]">Canvas is a trademark of Instructure, Inc. Not affiliated with or endorsed by Instructure.</p>
            </div>
        </AppLayout>
    );
}
