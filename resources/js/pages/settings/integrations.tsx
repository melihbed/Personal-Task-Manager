import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AppLayout from '../../layouts/app-layout';
import {
    filterIntegrations,
    integrationStates,
    overviewCounts,
    overviewFilters,
    type Integration,
    type Overview,
} from '../../lib/integrations';

type Props = { integrations: Integration[] };

const icons: Record<Overview, ReactNode> = {
    connected: <><circle cx="12" cy="12" r="9" /><path d="m8.5 12.5 2.5 2.5 4.5-5" /></>,
    error: <><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5M12 16.5h.01" /></>,
    recommended: <path d="m12 3 2.2 5.3 5.3 2.2-5.3 2.2L12 18l-2.2-5.3-5.3-2.2 5.3-2.2zM18.5 17v4M16.5 19h4" />,
};

function OverviewIcon({ name }: { name: Overview }) {
    return (
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="shrink-0">
            {icons[name]}
        </svg>
    );
}

/** The corner mark on a card: a green tick when connected, an amber alert when it needs attention. */
function StatusMark({ state }: { state: Integration['state'] }) {
    if (state === 'connected') {
        return (
            <span role="img" aria-label="Connected" title="Connected" className="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-[#16a34a] text-white">
                <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
            </span>
        );
    }

    if (state === 'needs_reconnect' || state === 'not_configured') {
        return (
            <span role="img" aria-label={integrationStates[state].label} title={integrationStates[state].label} className="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-[var(--pm-today)] text-white">
                <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round"><path d="M12 6v7M12 18h.01" /></svg>
            </span>
        );
    }

    return null;
}

function Card({ integration }: { integration: Integration }) {
    const state = integrationStates[integration.state];

    return (
        <li className="flex flex-col rounded-3xl border border-[var(--pm-border)] bg-white p-5 transition-shadow duration-200 hover:shadow-[0_8px_28px_#252b3d14]">
            <div className="flex items-center gap-3">
                {/* Google asks that its icon is only shown next to the product name, so the name is always beside it. */}
                <img src={integration.icon} alt="" width={36} height={36} className="size-9 shrink-0" />
                <h3 className="min-w-0 flex-1 truncate font-medium">{integration.name}</h3>
                <StatusMark state={integration.state} />
            </div>
            <p className="mt-3 line-clamp-3 text-sm text-[var(--pm-muted)]">{integration.description}</p>
            {integration.detail && <p className="mt-2 truncate text-xs text-[var(--pm-muted)]">{integration.detail}</p>}
            <div className="mt-auto flex items-center justify-between gap-3 pt-5">
                <span className={`pm-badge ${state.variant ? `pm-badge--${state.variant}` : ''}`}>{state.label}</span>
                <Link href={integration.href} aria-label={`View details: ${integration.name}`} className="pm-button pm-button--secondary pm-button--small">View details</Link>
            </div>
        </li>
    );
}

export default function Integrations({ integrations }: Props) {
    const [overview, setOverview] = useState<Overview | null>(null);
    const [query, setQuery] = useState('');
    const counts = overviewCounts(integrations);
    const visible = filterIntegrations(integrations, overview, query);
    const legal = [...new Set(integrations.map(integration => integration.legal).filter(Boolean))];
    const filtered = visible.length !== integrations.length;

    return (
        <AppLayout title="Integrations">
            <div className="rounded-3xl border border-[var(--pm-border)] bg-white lg:flex">
                <aside aria-label="Filter integrations" className="border-b border-[var(--pm-border)] p-5 lg:w-72 lg:shrink-0 lg:border-r lg:border-b-0">
                    <div className="relative" role="search">
                        <svg aria-hidden="true" className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-[var(--pm-muted)]" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <input
                            type="search"
                            aria-label="Search integrations"
                            placeholder="Search here"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            className="pm-input pm-input--icon"
                        />
                    </div>

                    <h2 className="mt-6 mb-2 px-3 text-sm font-medium text-[var(--pm-muted)]">Overview</h2>
                    <ul className="flex gap-1 overflow-x-auto lg:block lg:space-y-1 lg:overflow-visible">
                        {overviewFilters.map((filter) => {
                            const selected = overview === filter.value;

                            return (
                                <li key={filter.value} className="shrink-0 lg:shrink">
                                    <button
                                        type="button"
                                        aria-pressed={selected}
                                        onClick={() => setOverview(current => (current === filter.value ? null : filter.value))}
                                        className={`flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition-colors duration-150 ${selected ? 'bg-[var(--pm-background)] font-medium' : 'hover:bg-[var(--pm-background)]/60'}`}
                                    >
                                        <OverviewIcon name={filter.value} />
                                        <span className="flex-1 whitespace-nowrap">{filter.label}</span>
                                        <span className={`min-w-6 rounded-md px-1.5 py-0.5 text-center text-xs ${filter.value === 'error' && counts.error > 0 ? 'bg-[var(--pm-overdue-soft)] text-[var(--pm-overdue)]' : 'bg-[var(--pm-background)] text-[var(--pm-muted)]'}`}>
                                            {counts[filter.value]}
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </aside>

                <section aria-label="Available integrations" className="min-w-0 flex-1 p-5 lg:p-6">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <p className="text-sm text-[var(--pm-muted)]">Connect other apps to your planner. Each one can be switched off at any time.</p>
                        <p role="status" className="text-xs whitespace-nowrap text-[var(--pm-muted)]">
                            {filtered ? `${visible.length} of ${integrations.length} shown` : `${integrations.length} ${integrations.length === 1 ? 'integration' : 'integrations'}`}
                        </p>
                    </div>

                    {visible.length > 0 ? (
                        <ul className="mt-6 grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">
                            {visible.map(integration => <Card key={integration.key} integration={integration} />)}
                        </ul>
                    ) : (
                        <div className="mt-6 rounded-3xl border border-dashed border-[var(--pm-border)] px-6 py-12 text-center">
                            <p className="text-sm font-medium">No integrations match</p>
                            <p className="mt-1 text-xs text-[var(--pm-muted)]">Try a different search or clear the filters.</p>
                            <button type="button" onClick={() => { setQuery(''); setOverview(null); }} className="pm-button pm-button--secondary pm-button--small mt-4">Clear filters</button>
                        </div>
                    )}

                    <p className="mt-8 max-w-xl text-xs text-[var(--pm-muted)]">
                        {legal.join(' ')} Not affiliated with or endorsed by the companies named here.
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}
