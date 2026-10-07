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
    /** The calendars that are previewed on the planner calendar, and moved over by "Move everything". */
    import_calendar_ids: string[];
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
    /** How many Google events are already in the planner. */
    importedCount: number;
    /** Google events hidden from the planner calendar. */
    hiddenEvents: { id: number; label: string }[];
    /** Loaded after the page appears, because it asks Google. */
    calendars?: Calendar[];
};

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
function StatusSummary({ configured, state, account, icon, importedCount }: { configured: boolean; state: IntegrationState; account: Account | null; icon: string; importedCount: number }) {
    const connection = integrationStates[state === 'not_configured' ? 'not_connected' : state];
    const previewing = account !== null && !account.needs_reconnect && account.import_calendar_ids.length > 0;

    const rows: { label: string; badge: ReactNode; note?: string }[] = [
        { label: 'Google credentials', badge: <Badge variant={configured ? 'ok' : 'warn'}>{configured ? 'Set' : 'Missing'}</Badge>, note: configured ? undefined : 'Add them to .env first' },
        { label: 'Connection', badge: <Badge variant={connection.variant}>{connection.label}</Badge>, note: account?.email ?? undefined },
        { label: 'In your planner', badge: <Badge variant={importedCount > 0 ? 'ok' : ''}>{importedCount}</Badge>, note: importedCount === 1 ? 'event copied over' : 'events copied over' },
        { label: 'Google preview', badge: <Badge variant={previewing ? 'ok' : ''}>{previewing ? 'On' : 'Off'}</Badge> },
    ];

    return (
        <section className="pm-card" aria-label="Status">
            <div className="mb-4 flex items-center gap-3 border-b border-[var(--pm-border)] pb-4">
                <img src={icon} alt="" width={36} height={36} className="size-9 shrink-0" />
                <div>
                    <p className="font-medium">Google Calendar</p>
                    <p className="text-xs text-[var(--pm-muted)]">Bring your calendar into your planner</p>
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
        <Section title="Bring your calendar over">
            <p className="text-sm text-[var(--pm-muted)]">
                Move your Google events into this app, so your calendar, tasks and routines live in one place. The app only reads your calendar,
                and it never changes anything in Google. You can disconnect at any time.
            </p>
            <a href="/integrations/google/redirect" className="pm-button mt-5">Connect Google Calendar</a>
            <p className="mt-4 text-xs text-[var(--pm-muted)]">
                First time? While your Google app is in testing mode, only approved test users can connect. If you see “Access blocked”, add your Google account under{' '}
                <a className="pm-link" href={googleConsoleLinks.audience} target="_blank" rel="noopener noreferrer">Audience → Test users</a> and try again. More help is in Troubleshooting below.
            </p>
        </Section>
    );
}

function Connected({ account, calendars, importedCount, hiddenEvents }: { account: Account; calendars?: Calendar[]; importedCount: number; hiddenEvents: { id: number; label: string }[] }) {
    const { migrationNotes } = usePage<{ migrationNotes?: string[] }>().props;
    const [confirming, setConfirming] = useState<'migrate' | 'disconnect' | null>(null);
    const [busy, setBusy] = useState(false);

    // Google's "primary" alias stands for the main calendar; the list uses its real id.
    const resolve = (id: string) => (id === 'primary' ? calendars?.find(calendar => calendar.primary)?.id ?? id : id);
    const unique = (ids: string[]) => [...new Set(ids)];

    const form = useForm({ import_calendar_ids: account.import_calendar_ids });
    const { data, setData, patch, processing, errors } = form;
    const migration = usePage<{ errors: Record<string, string> }>().props.errors?.migration;

    useEffect(() => {
        if (!calendars) return;

        setData('import_calendar_ids', unique(data.import_calendar_ids.map(resolve)));
        // The aliases are resolved once, when the calendar list arrives.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [calendars]);

    const saved = unique(account.import_calendar_ids.map(resolve));
    const changed = [...data.import_calendar_ids].sort().join() !== [...saved].sort().join();
    const toggle = (id: string) => setData('import_calendar_ids', data.import_calendar_ids.includes(id) ? data.import_calendar_ids.filter(item => item !== id) : [...data.import_calendar_ids, id]);

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
                        <span className="mt-1 block text-xs text-[var(--pm-muted)]">Read-only. The app never changes your Google Calendar.</span>
                    </p>
                    <Button type="button" variant="danger" size="small" onClick={() => setConfirming('disconnect')}>Disconnect</Button>
                </div>
                {account.needs_reconnect && (
                    <div role="alert" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        <span>Google no longer accepts the saved connection, so nothing can be read.</span>
                        <a href="/integrations/google/redirect" className="pm-button pm-button--small">Reconnect</a>
                    </div>
                )}
            </Section>

            {!account.needs_reconnect && (
                <Section title="Move your calendar into the app">
                    <p className="text-sm text-[var(--pm-muted)]">
                        Copies every upcoming event from the calendars below into your planner as your own events, and repeating ones as routines. After that, the app is your calendar:
                        you can change, plan and delete everything here, and the preview of Google events switches off. Google is not changed.
                    </p>

                    {calendars === undefined ? <div className="mt-4"><Skeleton /></div> : calendars.length === 0 ? (
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span className="text-[var(--pm-muted)]">Could not load your Google calendars.</span>
                            <Button type="button" variant="secondary" size="small" onClick={() => router.reload({ only: ['calendars'] })}>Try again</Button>
                        </div>
                    ) : (
                        <form onSubmit={(event) => { event.preventDefault(); patch('/integrations/google', { preserveScroll: true }); }} className="mt-5">
                            <fieldset>
                                <legend className="text-sm font-medium">Calendars</legend>
                                <div className="mt-2 space-y-2">
                                    {calendars.map(calendar => (
                                        <label key={calendar.id} className="flex cursor-pointer items-center gap-3 text-sm">
                                            <input type="checkbox" checked={data.import_calendar_ids.includes(calendar.id)} onChange={() => toggle(calendar.id)} className="size-4 accent-[var(--pm-text)]" />
                                            <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: calendar.color ?? 'var(--pm-muted)' }} />
                                            {calendar.name}
                                        </label>
                                    ))}
                                </div>
                                {errors.import_calendar_ids && <p role="alert" className="mt-1 text-sm text-red-700">{errors.import_calendar_ids}</p>}
                            </fieldset>

                            <div className="pm-button-group mt-5">
                                <Button type="button" loading={busy && confirming === 'migrate'} loadingLabel="Moving your calendar" disabled={changed || processing || data.import_calendar_ids.length === 0 || busy} onClick={() => setConfirming('migrate')}>Move everything into my planner</Button>
                                <Button type="submit" variant="secondary" loading={processing} loadingLabel="Saving" disabled={!changed}>Save choice</Button>
                            </div>
                            {changed && <p className="mt-2 text-xs text-[var(--pm-muted)]">Save your choice of calendars before moving them.</p>}
                        </form>
                    )}

                    {migration && <p role="alert" className="mt-4 text-sm text-red-700">{migration}</p>}
                    {migrationNotes && migrationNotes.length > 0 && (
                        <ul className="mt-4 list-disc space-y-1 pl-5 text-xs text-[var(--pm-muted)]">{migrationNotes.map(note => <li key={note}>{note}</li>)}</ul>
                    )}
                    <p className="mt-4 text-xs text-[var(--pm-muted)]">{importedCount} {importedCount === 1 ? 'Google event is' : 'Google events are'} already in your planner. Running this again only brings in what is new.</p>
                </Section>
            )}

            {hiddenEvents.length > 0 && (
                <Section title="Hidden events">
                    <p className="text-sm text-[var(--pm-muted)]">These Google events are hidden from the preview and from moving over. They are still in Google Calendar.</p>
                    <ul className="mt-3 divide-y divide-[var(--pm-border)]">
                        {hiddenEvents.map(hidden => (
                            <li key={hidden.id} className="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                                <span className="min-w-0 truncate text-sm">{hidden.label}</span>
                                <Button type="button" variant="secondary" size="small" className="shrink-0" onClick={() => router.delete(`/integrations/google/hidden/${hidden.id}`, { preserveScroll: true })}>Show again</Button>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            {confirming === 'migrate' && (
                <ConfirmDialog
                    title="Move your Google calendar into the app?"
                    description="Every upcoming event from the chosen calendars becomes your own event here, and repeating ones become routines. The Google preview then switches off. Nothing in Google changes, and events you hid are left out."
                    confirmLabel="Move everything"
                    destructive={false}
                    busy={busy}
                    onConfirm={() => run(() => router.post('/integrations/google/migrate', { timezone: Intl.DateTimeFormat().resolvedOptions().timeZone }, { preserveScroll: true, onFinish: () => { setBusy(false); setConfirming(null); } }))}
                    onCancel={() => setConfirming(null)}
                />
            )}
            {confirming === 'disconnect' && (
                <ConfirmDialog
                    title="Disconnect Google Calendar?"
                    description="The app forgets its access to Google. Everything already copied into your planner stays."
                    confirmLabel="Disconnect"
                    busy={busy}
                    onConfirm={() => run(() => router.delete('/integrations/google', { onFinish: () => { setBusy(false); setConfirming(null); } }))}
                    onCancel={() => setConfirming(null)}
                />
            )}
        </>
    );
}

export default function GoogleCalendar({ configured, state, branding, troubleshoot, redirectUri, account, importedCount, hiddenEvents, calendars }: Props) {
    const { status } = usePage<{ status: string | null }>().props;

    return (
        <AppLayout title="Google Calendar" backHref="/integrations">
            <div className="mx-auto max-w-2xl space-y-5">
                {status && <p role="status" className="rounded-xl bg-[var(--pm-surface)] px-4 py-3 text-sm shadow-sm">{status}</p>}
                <StatusSummary configured={configured} state={state} account={account} icon={branding.icon} importedCount={importedCount} />
                {!configured && <Setup redirectUri={redirectUri} />}
                {configured && account === null && <Connect />}
                {account !== null && <Connected account={account} calendars={calendars} importedCount={importedCount} hiddenEvents={hiddenEvents} />}
                <Troubleshooting redirectUri={redirectUri} open={troubleshoot} />
                <p className="px-1 text-xs text-[var(--pm-muted)]">{branding.legal} Not affiliated with or endorsed by Google.</p>
            </div>
        </AppLayout>
    );
}
