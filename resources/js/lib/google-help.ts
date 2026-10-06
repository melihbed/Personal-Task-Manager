/** A step in a help topic. A step with a link shows the link after its text. */
export type HelpStep = string | { text: string; href: string; linkLabel: string };

export type HelpItem = {
    id: string;
    /** What the user sees, in Google's own words where possible, so they can find the right topic. */
    title: string;
    cause: string;
    steps: HelpStep[];
};

export const googleConsoleLinks = {
    audience: 'https://console.cloud.google.com/auth/audience',
    credentials: 'https://console.cloud.google.com/apis/credentials',
    calendarApi: 'https://console.cloud.google.com/apis/library/calendar-json.googleapis.com',
};

/**
 * The common ways connecting Google Calendar goes wrong, and how to fix each. The redirect URI is the one
 * this app actually uses, so it can be copied exactly.
 */
export function troubleshootingItems(redirectUri: string): HelpItem[] {
    return [
        {
            id: 'access-blocked',
            title: '“Access blocked: … has not completed the Google verification process” (Error 403: access_denied)',
            cause: 'Your Google app is in Testing mode, so only accounts on its list of test users can connect. The account you signed in with is not on that list yet.',
            steps: [
                { text: 'Open the Audience page in the Google Cloud console:', href: googleConsoleLinks.audience, linkLabel: 'Audience' },
                'Under “Test users”, choose “Add users”.',
                'Enter the exact Google account email you are signing in with (the one shown on the error screen), then save.',
                'Wait a minute, come back here, and choose Connect again.',
            ],
        },
        {
            id: 'unverified-warning',
            title: '“Google hasn’t verified this app”',
            cause: 'Normal for a personal app that has not been through Google’s review. It is not an error.',
            steps: [
                'Choose “Advanced” on the warning screen.',
                'Choose “Go to Personal Task Manager (unsafe)”. It is your own app.',
                'Allow both Calendar permissions, then continue.',
            ],
        },
        {
            id: 'redirect-mismatch',
            title: 'Error 400: redirect_uri_mismatch',
            cause: 'The redirect URI registered in Google does not match the one this app sends. It must match character for character.',
            steps: [
                { text: 'Open your OAuth client in the Google Cloud console:', href: googleConsoleLinks.credentials, linkLabel: 'Credentials' },
                `Under “Authorized redirect URIs”, add exactly: ${redirectUri}`,
                'Check the scheme (http or https), the host, the port, and that there is no trailing slash. Save and wait a few minutes.',
            ],
        },
        {
            id: 'invalid-client',
            title: '“The OAuth client was not found” or invalid_client (Error 401)',
            cause: 'The client ID or secret in this app’s settings is wrong, or the app has not picked up a recent change.',
            steps: [
                'Check GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in your .env file: no quotes, no spaces, copied in full.',
                'Run php artisan config:clear, then restart the app.',
            ],
        },
        {
            id: 'api-disabled',
            title: 'Connected, but calendars do not load',
            cause: 'The Google Calendar API is not turned on for your Google Cloud project.',
            steps: [
                { text: 'Open the Calendar API page and choose Enable:', href: googleConsoleLinks.calendarApi, linkLabel: 'Google Calendar API' },
                'Come back here and choose “Try again” in the calendar list.',
            ],
        },
        {
            id: 'weekly-expiry',
            title: 'The connection stops working after about a week',
            cause: 'While your Google app is in Testing mode, Google expires the saved access after 7 days. This app notices and asks you to reconnect.',
            steps: [
                'Choose Reconnect. It takes one click.',
                {
                    text: 'To stop it expiring, set the app’s publishing status to “In production” on the Audience page. It will keep showing the unverified-app warning, which is fine for personal use. Check Google’s current rules first:',
                    href: googleConsoleLinks.audience,
                    linkLabel: 'Audience',
                },
            ],
        },
    ];
}
