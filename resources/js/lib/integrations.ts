export type IntegrationState = 'not_configured' | 'not_connected' | 'needs_reconnect' | 'connected';

type Presentation = { label: string; variant: '' | 'ok' | 'warn'; action: string };

/** How each connection state is shown: its badge, and what the call to action says. */
export const integrationStates: Record<IntegrationState, Presentation> = {
    connected: { label: 'Connected', variant: 'ok', action: 'Manage' },
    needs_reconnect: { label: 'Needs reconnecting', variant: 'warn', action: 'Reconnect' },
    not_connected: { label: 'Not connected', variant: '', action: 'Connect' },
    not_configured: { label: 'Setup needed', variant: '', action: 'Set up' },
};

export type Integration = {
    key: string;
    name: string;
    description: string;
    href: string;
    icon: string;
    /** Trademark line shown at the foot of the page. */
    legal: string;
    state: IntegrationState;
    detail: string | null;
};

export type Overview = 'connected' | 'error' | 'recommended';

/**
 * The Overview filters. Error covers anything that needs attention: credentials missing, or a connection
 * Google no longer accepts. Recommended is an integration that is set up but not connected yet.
 * There is no "all" entry: with nothing selected, every integration is shown.
 */
export const overviewFilters: { value: Overview; label: string; matches: (state: IntegrationState) => boolean }[] = [
    { value: 'connected', label: 'Connected', matches: state => state === 'connected' },
    { value: 'error', label: 'Error', matches: state => state === 'needs_reconnect' || state === 'not_configured' },
    { value: 'recommended', label: 'Recommended', matches: state => state === 'not_connected' },
];

export function overviewCounts(integrations: Integration[]): Record<Overview, number> {
    return Object.fromEntries(
        overviewFilters.map(filter => [filter.value, integrations.filter(integration => filter.matches(integration.state)).length]),
    ) as Record<Overview, number>;
}

/** Integrations in the chosen Overview group (all of them when none is chosen) whose name or description contains the search text. */
export function filterIntegrations(integrations: Integration[], overview: Overview | null, query: string): Integration[] {
    const matches = overviewFilters.find(filter => filter.value === overview)?.matches ?? (() => true);
    const text = query.trim().toLowerCase();

    return integrations.filter(integration =>
        matches(integration.state) && (text === '' || `${integration.name} ${integration.description}`.toLowerCase().includes(text)),
    );
}
