import { describe, expect, test } from 'vitest';
import { googleConsoleLinks, troubleshootingItems, type HelpStep } from '../../resources/js/lib/google-help';
import { filterIntegrations, integrationStates, overviewCounts, type Integration, type IntegrationState } from '../../resources/js/lib/integrations';

const uri = 'http://localhost:8000/integrations/google/callback';
const stepText = (step: HelpStep) => (typeof step === 'string' ? step : step.text);

describe('troubleshooting help', () => {
    const items = troubleshootingItems(uri);

    test('covers the access blocked error with the fix for it', () => {
        const item = items.find(entry => entry.id === 'access-blocked');

        expect(item?.title).toContain('Error 403: access_denied');
        expect(item?.cause).toContain('Testing mode');
        expect(item?.steps.map(stepText).join(' ')).toContain('Test users');
        expect(item?.steps.some(step => typeof step !== 'string' && step.href === googleConsoleLinks.audience)).toBe(true);
    });

    test('shows the exact redirect URI this app uses', () => {
        const item = items.find(entry => entry.id === 'redirect-mismatch');

        expect(item?.steps.map(stepText).join(' ')).toContain(uri);
    });

    test('every topic has a unique id, a cause and at least one step', () => {
        expect(new Set(items.map(item => item.id)).size).toBe(items.length);

        for (const item of items) {
            expect(item.title).not.toBe('');
            expect(item.cause).not.toBe('');
            expect(item.steps.length).toBeGreaterThan(0);
        }
    });

    test('links only go to Google Cloud over https', () => {
        const links = items.flatMap(item => item.steps).filter((step): step is Exclude<HelpStep, string> => typeof step !== 'string');

        expect(links.length).toBeGreaterThan(0);
        for (const link of links) expect(link.href).toMatch(/^https:\/\/console\.cloud\.google\.com\//);
    });
});

describe('integration states', () => {
    test('each state has a label and an action', () => {
        expect(Object.keys(integrationStates).sort()).toEqual(['connected', 'needs_reconnect', 'not_configured', 'not_connected']);
        expect(integrationStates.connected).toEqual({ label: 'Connected', variant: 'ok', action: 'Manage' });
        expect(integrationStates.needs_reconnect.variant).toBe('warn');
        expect(integrationStates.not_configured.action).toBe('Set up');
    });
});

describe('filtering integrations', () => {
    const make = (key: string, name: string, state: IntegrationState, description = `About ${name}`): Integration => ({
        key, name, description, state, href: `/integrations/${key}`, icon: `/images/${key}.png`, legal: '', detail: null,
    });
    const list = [
        make('google', 'Google Calendar', 'connected', 'Show your Google events next to your plan.'),
        make('outlook', 'Outlook', 'needs_reconnect'),
        make('notion', 'Notion', 'not_configured'),
        make('todoist', 'Todoist', 'not_connected'),
    ];

    test('counts each Overview group', () => {
        expect(overviewCounts(list)).toEqual({ connected: 1, error: 2, recommended: 1 });
    });

    test('Error covers both a connection that needs renewing and missing credentials', () => {
        expect(filterIntegrations(list, 'error', '').map(item => item.key)).toEqual(['outlook', 'notion']);
    });

    test('with no group chosen every integration is shown', () => {
        expect(filterIntegrations(list, null, '').map(item => item.key)).toEqual(['google', 'outlook', 'notion', 'todoist']);
    });

    test('Recommended is set up but not connected, and Connected is connected', () => {
        expect(filterIntegrations(list, 'recommended', '').map(item => item.key)).toEqual(['todoist']);
        expect(filterIntegrations(list, 'connected', '').map(item => item.key)).toEqual(['google']);
        expect(filterIntegrations(list, null, '')).toHaveLength(4);
    });

    test('search matches the name or the description, ignoring case and surrounding spaces', () => {
        expect(filterIntegrations(list, null, '  CALENDAR ').map(item => item.key)).toEqual(['google']);
        expect(filterIntegrations(list, null, 'your plan').map(item => item.key)).toEqual(['google']);
        expect(filterIntegrations(list, null, 'zzz')).toEqual([]);
    });

    test('search and an Overview group combine', () => {
        expect(filterIntegrations(list, 'error', 'outlook').map(item => item.key)).toEqual(['outlook']);
        expect(filterIntegrations(list, 'connected', 'outlook')).toEqual([]);
    });

    test('there is nothing to show for an empty list', () => {
        expect(filterIntegrations([], null, '')).toEqual([]);
        expect(overviewCounts([])).toEqual({ connected: 0, error: 0, recommended: 0 });
    });
});
