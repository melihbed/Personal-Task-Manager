import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import Button from '../../components/ui/button';
import ConfirmDialog from '../../components/ui/confirm-dialog';
import Disclosure from '../../components/ui/disclosure';
import AppLayout from '../../layouts/app-layout';
import { googleConsoleLinks, troubleshootingItems } from '../../lib/google-help';
import { integrationStates, type IntegrationState } from '../../lib/integrations';

type Calendar = { id: string; name: string; primary: boolean; writable: boolean; color: string | null };
type Account = {
    email: string | null;
    needs_reconnect: boolean;
    calendar_id: string | null;
    calendar_name: string | null;
    import_calendar_ids: string[];
    push_sessions: boolean;
    push_routines: boolean;
    push_deadlines: boolean;
};
type Props = {
    configured: boolean;
    state: IntegrationState;
    /** Google's product icon and trademark line. The icon is only ever shown beside the product name. */
    branding: { icon: string; legal: string };
    /** Open the troubleshooting section, after a failed attempt to connect. */
    troubleshoot: boolean;
    redirectUri: string;
    account: Account | null;
    pushedEvents: number;
    /** Loaded after the page appears, because it asks Google. */
    calendars?: Calendar[];
};

const pushOptions = [
    { field: 'push_sessions', label: 'Work sessions', hint: 'Time you plan on the calendar for a task.' },
    { field: 'push_routines', label: 'Routines', hint: 'Each routine becomes one repeating event, with skipped and moved days.' },
    { field: 'push_deadlines', label: 'Task deadlines', hint: 'A short event at the due time, or an all-day event for a date-only deadline.' },
] as const;

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="pm-card">
            <h3 className="text-lg font-medium">{title}</h3>
            <div className="mt-4">{children}</div>
        </section>
    );
}

function Skeleton() {
    return (
        <div className="animate-pulse space-y-3" role="status" aria-label="Loading your Google calendars">
            <div className="h-10 rounded-xl bg-[var(--pm-background)]" />
            <div className="h-4 w-2/3 rounded bg-[var(--pm-background)]" />
            <div className="h-4 w-1/2 rounded bg-[var(--pm-background)]" />
        </div>
    );
}

function Badge({ variant, children }: { variant: '' | 'ok' | 'warn'; children: ReactNode }) {
    return <span className={`pm-badge ${variant ? `pm-badge--${variant}` : ''}`}>{children}</span>;
}

/** Where things stand at a glance: credentials, connection, and whether anything is being sent to Google. */
function StatusSummary({ configured, state, account, icon }: { configured: boolean; state: IntegrationState; account: Account | null; icon: string }) {
    const connection = integrationStates[state === 'not_configured' ? 'not_connected' : state];
    const sending = account !== null && !account.needs_reconnect && (account.push_sessions || account.push_routines || account.push_deadlines);

    const rows: { label: string; badge: ReactNode; note?: string }[] = [
        { label: 'Google credentials', badge: <Badge variant={configured ? 'ok' : 'warn'}>{configured ? 'Set' : 'Missing'}</Badge>, note: configured ? undefined : 'Add them to .env first' },
        { label: 'Connection', badge: <Badge variant={connection.variant}>{connection.label}</Badge>, note: account?.email ?? undefined },
        {
            label: 'Adding to Google',
            badge: <Badge variant={sending ? 'ok' : account?.needs_reconnect ? 'warn' : ''}>{sending ? 'On' : account?.needs_reconnect ? 'Paused' : 'Off'}</Badge>,
        },
    ];

    return (
        <section className="pm-card" aria-label="Status">
            <div className="mb-4 flex items-center gap-3 border-b border-[var(--pm-border)] pb-4">
                <img src={icon} alt="" width={36} height={36} className="size-9 shrink-0" />
                <div>
                    <p className="font-medium">Google Calendar</p>
                    <p className="text-xs text-[var(--pm-muted)]">Sync your plan with your Google account</p>
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

function Troubleshooting({ redirectUri, open }: { redirectUri: string; open: boolean }) {
    return (
        <section id="troubleshooting" className="pm-card">
            <h3 className="text-lg font-medium">Troubleshooting</h3>
            <p className="mt-1 text-sm text-[var(--pm-muted)]">Seeing an error from Google? Find it below.</p>
            <div className="mt-3">
                {troubleshootingItems(redirectUri).map(item => (
                    <Disclosure key={item.id} title={item.title} defaultOpen={open && item.id === 'access-blocked'}>
                        <p className="text-[var(--pm-muted)]">{item.cause}</p>
                        <ol className="mt-3 list-decimal space-y-2 pl-5">
                            {item.steps.map((step, index) => (
                                <li key={index}>
                                    {typeof step === 'string' ? step : (
                                        <>
                                            {step.text}{' '}
                                            <a className="pm-link" href={step.href} target="_blank" rel="noopener noreferrer">{step.linkLabel}</a>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </Disclosure>
                ))}
            </div>
        </section>
    );
}

function Setup({ redirectUri }: { redirectUri: string }) {
    return (
        <Section title="Set up Google access">
            <p className="text-sm text-[var(--pm-muted)]">This app needs its own Google credentials before anyone can connect a calendar. It takes a few minutes, once.</p>
            <ol className="mt-4 list-decimal space-y-3 pl-5 text-sm">
                <li>
                    In the{' '}
                    <a className="pm-link" href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud console</a>, enable the <strong>Google Calendar API</strong> and create an <strong>OAuth client ID</strong> of type “Web application”.
                </li>
                <li>
                    Add this as an authorized redirect URI:
                    <code className="mt-1 block rounded-lg bg-[var(--pm-background)] px-3 py-2 text-xs break-all">{redirectUri}</code>
                </li>
                <li>On the consent screen, add your own email as a test user.</li>
                <li>
                    Put the client ID and secret in your <code className="rounded bg-[var(--pm-background)] px-1.5 py-0.5 text-xs">.env</code> file as <code className="rounded bg-[var(--pm-background)] px-1.5 py-0.5 text-xs">GOOGLE_CLIENT_ID</code> and <code className="rounded bg-[var(--pm-background)] px-1.5 py-0.5 text-xs">GOOGLE_CLIENT_SECRET</code>, then restart the app.
                </li>
            </ol>
        </Section>
    );
}

function Connect() {
    return (
        <Section title="Connect your calendar">
            <p className="text-sm text-[var(--pm-muted)]">
                Show your Google events next to your plan, and add your work sessions, routines and deadlines to Google Calendar.
                The app asks only to view and edit events and to list your calendars. You can disconnect at any time.
            </p>
            <a href="/integrations/google/redirect" className="pm-button mt-5">Connect Google Calendar</a>
            <p className="mt-4 text-xs text-[var(--pm-muted)]">
                First time? While your Google app is in testing mode, only approved test users can connect. If you see “Access blocked”, add your Google account under{' '}
                <a className="pm-link" href={googleConsoleLinks.audience} target="_blank" rel="noopener noreferrer">Audience → Test users</a> and try again. More help is in Troubleshooting below.
            </p>
        </Section>
    );
}

function Connected({ account, calendars, pushedEvents }: { account: Account; calendars?: Calendar[]; pushedEvents: number }) {
    const [confirming, setConfirming] = useState<'remove' | 'disconnect' | null>(null);
    const [busy, setBusy] = useState(false);
    const [syncing, setSyncing] = useState(false);

    // Google's "primary" alias stands for the main calendar; the list uses its real id.
    const resolve = (id: string | null) => (id === 'primary' ? calendars?.find(calendar => calendar.primary)?.id ?? id : id) ?? '';
    const unique = (ids: string[]) => [...new Set(ids)];

    const form = useForm({
        calendar_id: account.calendar_id ?? '',
        import_calendar_ids: account.import_calendar_ids,
        push_sessions: account.push_sessions,
        push_routines: account.push_routines,
        push_deadlines: account.push_deadlines,
    });
    const { data, setData, patch, processing, errors } = form;

    useEffect(() => {
        if (!calendars) return;

        setData(current => ({ ...current, calendar_id: resolve(current.calendar_id), import_calendar_ids: unique(current.import_calendar_ids.map(resolve)) }));
        // The aliases are resolved once, when the calendar list arrives.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [calendars]);

    const saved = {
        calendar_id: resolve(account.calendar_id),
        import_calendar_ids: unique(account.import_calendar_ids.map(resolve)),
    };
    const changed = data.calendar_id !== saved.calendar_id
        || [...data.import_calendar_ids].sort().join() !== [...saved.import_calendar_ids].sort().join()
        || pushOptions.some(option => data[option.field] !== account[option.field]);

    function toggleImport(id: string) {
        setData('import_calendar_ids', data.import_calendar_ids.includes(id) ? data.import_calendar_ids.filter(item => item !== id) : [...data.import_calendar_ids, id]);
    }

    function run(action: () => void) {
        setBusy(true);
        action();
    }

    return (
        <>
            <Section title="Connection">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm">
                        Connected as <strong>{account.email ?? 'your Google account'}</strong>
                        <span className="mt-1 block text-xs text-[var(--pm-muted)]">{pushedEvents} {pushedEvents === 1 ? 'event' : 'events'} added to Google Calendar</span>
                    </p>
                    <Button type="button" variant="danger" size="small" onClick={() => setConfirming('disconnect')}>Disconnect</Button>
                </div>
                {account.needs_reconnect && (
                    <div role="alert" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        <span>Google no longer accepts the saved connection, so syncing is paused.</span>
                        <a href="/integrations/google/redirect" className="pm-button pm-button--small">Reconnect</a>
                    </div>
                )}
            </Section>

            {!account.needs_reconnect && (
                <Section title="Choose what to sync">
                    {calendars === undefined ? <Skeleton /> : calendars.length === 0 ? (
                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span className="text-[var(--pm-muted)]">Could not load your Google calendars.</span>
                            <Button type="button" variant="secondary" size="small" onClick={() => router.reload({ only: ['calendars'] })}>Try again</Button>
                        </div>
                    ) : (
                        <form onSubmit={(event) => { event.preventDefault(); patch('/integrations/google', { preserveScroll: true }); }} className="space-y-6">
                            <div>
                                <label htmlFor="google-calendar" className="text-sm font-medium">Add planner items to</label>
                                <select id="google-calendar" value={data.calendar_id} onChange={(event) => setData('calendar_id', event.target.value)} className="pm-input cursor-pointer">
                                    {calendars.filter(calendar => calendar.writable).map(calendar => (
                                        <option key={calendar.id} value={calendar.id}>{calendar.name}{calendar.primary ? ' (primary)' : ''}</option>
                                    ))}
                                </select>
                                <p className="mt-1.5 text-xs text-[var(--pm-muted)]">A separate “Planner” calendar keeps these events easy to hide or remove.</p>
                                {errors.calendar_id && <p role="alert" className="mt-1 text-sm text-red-700">{errors.calendar_id}</p>}
                            </div>

                            <fieldset>
                                <legend className="text-sm font-medium">What to add to Google</legend>
                                <div className="mt-2 space-y-3">
                                    {pushOptions.map(option => (
                                        <label key={option.field} className="flex cursor-pointer items-start gap-3">
                                            <input type="checkbox" checked={data[option.field]} onChange={(event) => setData(option.field, event.target.checked)} className="mt-1 size-4 accent-[var(--pm-text)]" />
                                            <span className="text-sm">{option.label}<span className="block text-xs text-[var(--pm-muted)]">{option.hint}</span></span>
                                        </label>
                                    ))}
                                </div>
                            </fieldset>

                            <fieldset>
                                <legend className="text-sm font-medium">Show events from</legend>
                                <p className="mt-1 text-xs text-[var(--pm-muted)]">These appear on your planner calendar, read-only, so you can see what overlaps.</p>
                                <div className="mt-2 space-y-2">
                                    {calendars.map(calendar => (
                                        <label key={calendar.id} className="flex cursor-pointer items-center gap-3 text-sm">
                                            <input type="checkbox" checked={data.import_calendar_ids.includes(calendar.id)} onChange={() => toggleImport(calendar.id)} className="size-4 accent-[var(--pm-text)]" />
                                            <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: calendar.color ?? 'var(--pm-muted)' }} />
                                            {calendar.name}
                                        </label>
                                    ))}
                                </div>
                                {errors.import_calendar_ids && <p role="alert" className="mt-1 text-sm text-red-700">{errors.import_calendar_ids}</p>}
                            </fieldset>

                            <div className="pm-button-group">
                                <Button type="submit" loading={processing} loadingLabel="Saving" disabled={!changed}>Save changes</Button>
                            </div>
                        </form>
                    )}
                </Section>
            )}

            {!account.needs_reconnect && (
                <Section title="Sync">
                    <p className="text-sm text-[var(--pm-muted)]">
                        Changes are sent to Google automatically, within a few seconds. Use these if something looks out of step.
                    </p>
                    <div className="pm-button-group mt-4">
                        <Button
                            type="button"
                            variant="secondary"
                            loading={syncing}
                            loadingLabel="Syncing"
                            onClick={() => { setSyncing(true); router.post('/integrations/google/sync', {}, { preserveScroll: true, onFinish: () => setSyncing(false) }); }}
                        >
                            Sync now
                        </Button>
                        <Button type="button" variant="danger" onClick={() => setConfirming('remove')}>Remove planner events from Google</Button>
                    </div>
                </Section>
            )}

            {confirming === 'remove' && (
                <ConfirmDialog
                    title="Remove planner events from Google?"
                    description="Every work session, routine and deadline this app added to Google Calendar is deleted there. Your planner is not changed, and they come back the next time you sync."
                    confirmLabel="Remove events"
                    busy={busy}
                    onConfirm={() => run(() => router.delete('/integrations/google/sync', { preserveScroll: true, onSuccess: () => setConfirming(null), onFinish: () => setBusy(false) }))}
                    onCancel={() => setConfirming(null)}
                />
            )}
            {confirming === 'disconnect' && (
                <ConfirmDialog
                    title="Disconnect Google Calendar?"
                    description="The app stops syncing and forgets its access. Events it already added stay in Google Calendar; use “Remove planner events” first if you want them gone."
                    confirmLabel="Disconnect"
                    busy={busy}
                    onConfirm={() => run(() => router.delete('/integrations/google', { onFinish: () => { setBusy(false); setConfirming(null); } }))}
                    onCancel={() => setConfirming(null)}
                />
            )}
        </>
    );
}

export default function GoogleCalendar({ configured, state, branding, troubleshoot, redirectUri, account, pushedEvents, calendars }: Props) {
    const { status } = usePage<{ status: string | null }>().props;

    return (
        <AppLayout title="Google Calendar" backHref="/integrations">
            <div className="mx-auto max-w-2xl space-y-5">
                {status && <p role="status" className="rounded-xl bg-[var(--pm-surface)] px-4 py-3 text-sm shadow-sm">{status}</p>}
                <StatusSummary configured={configured} state={state} account={account} icon={branding.icon} />
                {!configured && <Setup redirectUri={redirectUri} />}
                {configured && account === null && <Connect />}
                {account !== null && <Connected account={account} calendars={calendars} pushedEvents={pushedEvents} />}
                <Troubleshooting redirectUri={redirectUri} open={troubleshoot} />
                <p className="px-1 text-xs text-[var(--pm-muted)]">{branding.legal} Not affiliated with or endorsed by Google.</p>
            </div>
        </AppLayout>
    );
}
