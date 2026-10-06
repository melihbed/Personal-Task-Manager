<?php

namespace App\Http\Controllers;

use App\Models\GoogleAccount;
use App\Services\GoogleCalendar\GoogleSyncDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleOAuthController extends Controller
{
    /** Read and write events, and list calendars. Nothing broader. */
    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
    ];

    public function redirect(): RedirectResponse
    {
        if (! GoogleAccount::isConfigured()) {
            return redirect()->route('google.show')->with('status', 'Google Calendar is not set up yet. Add the Google client ID and secret first.');
        }

        // offline + consent make Google return a refresh token, so syncing keeps working after the first hour.
        return Socialite::driver('google')
            ->scopes(self::SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    /**
     * Saves the authorization for the signed-in user. This connects a calendar to an existing account;
     * it never signs anyone in.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->troubleshoot($this->deniedMessage((string) $request->query('error', '')));
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            report($exception);

            return $this->troubleshoot('Could not connect to Google. Please try again.');
        }

        $user = $request->user();
        $account = $user->googleAccount ?? new GoogleAccount([
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary calendar',
            'import_calendar_ids' => ['primary'],
        ]);

        $account->fill([
            'email' => $google->getEmail(),
            'access_token' => $google->token,
            // Google only sends a refresh token on consent; keep the old one if this response has none.
            'refresh_token' => $google->refreshToken ?: $account->refresh_token,
            'expires_at' => now()->addSeconds((int) ($google->expiresIn ?? 3600)),
            'needs_reconnect' => false,
        ]);
        $account->user()->associate($user);
        $account->save();

        GoogleSyncDispatcher::all($user, ['session', 'deadline', 'routine']);

        return redirect()->route('google.show')->with('status', 'Google Calendar connected.');
    }

    /** Back to the settings page with the troubleshooting section open. */
    private function troubleshoot(string $message): RedirectResponse
    {
        return redirect()->route('google.show', ['troubleshoot' => 1])->with('status', $message);
    }

    /**
     * What to tell the user when Google sends them back without a code. Only a plain error code is
     * echoed, never free text from the query string.
     */
    private function deniedMessage(string $error): string
    {
        if ($error === 'access_denied') {
            return 'Google did not give access. If you saw “Access blocked”, add your Google account as a test user (see Troubleshooting below), or you may have chosen Cancel.';
        }

        if ($error !== '' && preg_match('/^[a-z_]{1,40}$/', $error) === 1) {
            return "Google could not connect the calendar ({$error}). See Troubleshooting below.";
        }

        return 'Google Calendar was not connected.';
    }
}
