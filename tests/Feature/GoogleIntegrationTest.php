<?php

use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarBranding;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function configureGoogle(): void
{
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
        'services.google.redirect' => 'http://localhost/integrations/google/callback',
    ]);
}

function googleProfile(?string $refreshToken = 'new-refresh'): SocialiteUser
{
    return (new SocialiteUser)
        ->map(['id' => 'g-1', 'name' => 'Me', 'email' => 'me@example.com'])
        ->setToken('new-access')
        ->setRefreshToken($refreshToken)
        ->setExpiresIn(3600);
}

describe('connecting', function () {
    test('guests cannot open the Google settings', function () {
        $this->get('/integrations/google')->assertRedirect('/login');
        $this->get('/integrations/google/redirect')->assertRedirect('/login');
    });

    test('connecting without credentials explains what is missing', function () {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/integrations/google/redirect')
            ->assertRedirect('/integrations/google')
            ->assertSessionHas('status');
    });

    test('connecting sends the user to Google asking for offline, read-only access to events', function () {
        configureGoogle();

        $response = $this->actingAs(User::factory()->create())->get('/integrations/google/redirect');

        $url = urldecode($response->headers->get('Location'));

        expect($url)->toStartWith('https://accounts.google.com/o/oauth2/auth')
            ->toContain('access_type=offline')
            ->toContain('prompt=consent')
            ->toContain('https://www.googleapis.com/auth/calendar.events.readonly')
            ->toContain('https://www.googleapis.com/auth/calendar.calendarlist.readonly')
            ->not->toMatch('#auth/calendar\.events(?!\.readonly)#')
            ->not->toContain('auth/calendar ');
    });

    test('the callback saves encrypted tokens for the signed in user', function () {
        configureGoogle();
        $user = User::factory()->create();
        Socialite::fake('google', googleProfile());

        $this->actingAs($user)->get('/integrations/google/callback?code=abc')
            ->assertRedirect('/integrations/google')
            ->assertSessionHas('status', 'Google Calendar connected.');

        $account = $user->googleAccount()->first();

        expect($account->email)->toBe('me@example.com')
            ->and($account->access_token)->toBe('new-access')
            ->and($account->refresh_token)->toBe('new-refresh')
            ->and($account->import_calendar_ids)->toBe(['primary'])
            ->and($account->needs_reconnect)->toBeFalse()
            ->and(DB::table('google_accounts')->value('access_token'))->not->toBe('new-access')
            ->and(DB::table('google_accounts')->value('refresh_token'))->not->toBe('new-refresh');
    });

    test('reconnecting keeps the chosen calendars and the old refresh token when Google sends none', function () {
        configureGoogle();
        $user = User::factory()->create();
        $user->googleAccount()->create([
            'access_token' => 'stale', 'refresh_token' => 'old-refresh', 'import_calendar_ids' => ['cal-1'], 'needs_reconnect' => true,
        ]);
        Socialite::fake('google', googleProfile(null));

        $this->actingAs($user)->get('/integrations/google/callback?code=abc');

        $account = $user->googleAccount()->first();

        expect($account->access_token)->toBe('new-access')
            ->and($account->refresh_token)->toBe('old-refresh')
            ->and($account->import_calendar_ids)->toBe(['cal-1'])
            ->and($account->needs_reconnect)->toBeFalse();
    });

    test('declining on the Google consent screen connects nothing', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/integrations/google/callback?error=access_denied')
            ->assertRedirect('/integrations/google?troubleshoot=1')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'add your Google account as a test user'));

        expect(GoogleAccount::count())->toBe(0);
    });

    test('other Google errors name the error code and point to troubleshooting', function () {
        $this->actingAs(User::factory()->create())->get('/integrations/google/callback?error=invalid_request')
            ->assertRedirect('/integrations/google?troubleshoot=1')
            ->assertSessionHas('status', 'Google could not connect the calendar (invalid_request). See Troubleshooting below.');
    });

    test('an error value that is not a plain code is never echoed back', function (string $error) {
        $response = $this->actingAs(User::factory()->create())->get('/integrations/google/callback?error='.urlencode($error));

        $response->assertRedirect('/integrations/google?troubleshoot=1')
            ->assertSessionHas('status', 'Google Calendar was not connected.');
    })->with([
        'markup' => ['<script>alert(1)</script>'],
        'too long' => [str_repeat('a', 80)],
        'with spaces' => ['access denied by admin'],
    ]);

    test('coming back without a code or an error is handled', function () {
        $this->actingAs(User::factory()->create())->get('/integrations/google/callback')
            ->assertRedirect('/integrations/google?troubleshoot=1')
            ->assertSessionHas('status', 'Google Calendar was not connected.');
    });

    test('a failed token exchange is reported kindly and connects nothing', function () {
        configureGoogle();
        $user = User::factory()->create();
        Socialite::fake('google', fn () => throw new RuntimeException('boom'));

        $this->actingAs($user)->get('/integrations/google/callback?code=abc')
            ->assertRedirect('/integrations/google?troubleshoot=1')
            ->assertSessionHas('status', 'Could not connect to Google. Please try again.');

        expect(GoogleAccount::count())->toBe(0);
    });
});

describe('the integrations page', function () {
    test('guests cannot open it', function () {
        $this->get('/integrations')->assertRedirect('/login');
    });

    test('it lists Google Calendar with the state of the connection', function (callable $arrange, string $state, ?string $detail) {
        $user = $arrange();

        $this->actingAs($user)->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/integrations')
                ->has('integrations', 2)
                ->where('integrations.0.key', 'google-calendar')
                ->where('integrations.0.name', 'Google Calendar')
                ->where('integrations.0.href', route('google.show'))
                ->where('integrations.0.icon', asset('images/integrations/google-calendar.png'))
                ->where('integrations.0.legal', 'Google Calendar is a trademark of Google LLC.')
                ->where('integrations.0.state', $state)
                ->where('integrations.0.detail', $detail));
    })->with([
        'credentials are missing' => [function () {
            config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

            return User::factory()->create();
        }, 'not_configured', null],
        'configured but not connected' => [function () {
            configureGoogle();

            return User::factory()->create();
        }, 'not_connected', null],
        'connected' => [function () {
            configureGoogle();

            return googleUser();
        }, 'connected', 'me@example.com'],
        'the connection needs renewing' => [function () {
            configureGoogle();

            return googleUser(['needs_reconnect' => true]);
        }, 'needs_reconnect', 'me@example.com'],
    ]);
});

describe('branding', function () {
    test('the Google Calendar icon is a real PNG that ships with the app', function () {
        $path = public_path(GoogleCalendarBranding::ICON);

        expect(file_exists($path))->toBeTrue()
            ->and(file_get_contents($path, false, null, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
            ->and(getimagesize($path)[0])->toBeGreaterThanOrEqual(96);
    });

    test('the settings page gets the icon and the trademark line', function () {
        configureGoogle();

        $this->actingAs(User::factory()->create())->get('/integrations/google')
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.icon', asset('images/integrations/google-calendar.png'))
                ->where('branding.legal', GoogleCalendarBranding::LEGAL));
    });
});

describe('settings', function () {
    test('the settings page reports the connection state and opens troubleshooting on request', function () {
        configureGoogle();
        $user = googleUser(['needs_reconnect' => true]);

        $this->actingAs($user)->get('/integrations/google?troubleshoot=1')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'needs_reconnect')->where('troubleshoot', true));

        $this->actingAs($user)->get('/integrations/google')
            ->assertInertia(fn (Assert $page) => $page->where('troubleshoot', false));
    });

    test('the settings page works before connecting', function () {
        configureGoogle();

        $this->actingAs(User::factory()->create())->get('/integrations/google')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/google-calendar')
                ->where('configured', true)
                ->where('state', 'not_connected')
                ->where('troubleshoot', false)
                ->where('account', null)
                ->where('redirectUri', 'http://localhost/integrations/google/callback'));
    });

    test('the settings page shows the account without ever exposing tokens', function () {
        fakeGoogle();
        $user = googleUser();

        $this->actingAs($user)->get('/integrations/google')
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.email', 'me@example.com')
                ->where('account.import_calendar_ids', ['primary'])
                ->missing('account.access_token')
                ->missing('account.refresh_token')
                ->loadDeferredProps(fn (Assert $loaded) => $loaded->has('calendars', 3)->where('calendars.1.name', 'Planner')));
    });

    test('settings can only be changed for a connected account', function () {
        $this->actingAs(User::factory()->create())->patch('/integrations/google', settingsPayload())->assertNotFound();
        $this->actingAs(googleUser(['needs_reconnect' => true]))->patch('/integrations/google', settingsPayload())->assertNotFound();
    });

    function settingsPayload(array $overrides = []): array
    {
        return array_merge(['import_calendar_ids' => ['primary-id']], $overrides);
    }

    test('the previewed calendars are saved', function () {
        fakeGoogle();
        $user = googleUser();

        $this->actingAs($user)->patch('/integrations/google', settingsPayload(['import_calendar_ids' => ['primary-id', 'holidays']]))
            ->assertSessionHasNoErrors();

        expect($user->googleAccount()->first()->import_calendar_ids)->toBe(['primary-id', 'holidays']);
    });

    test('the previewed calendars can be switched off completely', function () {
        fakeGoogle();
        $user = googleUser();

        $this->actingAs($user)->patch('/integrations/google', ['import_calendar_ids' => []])->assertSessionHasNoErrors();

        expect($user->googleAccount()->first()->import_calendar_ids)->toBe([]);
    });

    test('the primary alias is saved as the real calendar id', function () {
        fakeGoogle();
        $user = googleUser();

        $this->actingAs($user)->patch('/integrations/google', settingsPayload(['import_calendar_ids' => ['primary']]));

        expect($user->googleAccount()->first()->import_calendar_ids)->toBe(['primary-id']);
    });

    test('settings reject calendars that are not in the users own list', function () {
        fakeGoogle();

        $this->actingAs(googleUser())->patch('/integrations/google', settingsPayload(['import_calendar_ids' => ['nope']]))->assertSessionHasErrors('import_calendar_ids');
    });

    test('settings reject malformed input', function (array $payload, string $field) {
        fakeGoogle();

        $this->actingAs(googleUser())->patch('/integrations/google', $payload)->assertSessionHasErrors($field);
    })->with([
        'no list of calendars' => [[], 'import_calendar_ids'],
        'duplicate calendars' => [['import_calendar_ids' => ['primary-id', 'primary-id']], 'import_calendar_ids.0'],
    ]);

    test('the app never writes to Google, so connecting asks for no write access and nothing is ever sent', function () {
        fakeGoogle();
        $user = googleUser();
        plannedSession($user);
        weeklyRoutine($user);
        $user->tasks()->create(['title' => 'Call', 'priority' => 'normal', 'due_at' => '2026-10-07T18:00:00Z']);

        expect(googleWrites())->toHaveCount(0)->and(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'googleapis.com/calendar')))->toHaveCount(0);
    });

    test('disconnecting revokes access and forgets the account, leaving Google events alone', function () {
        fakeGoogle();
        $user = googleUser();
        plannedSession($user);

        $this->actingAs($user)->delete('/integrations/google')
            ->assertRedirect('/integrations/google')
            ->assertSessionHas('status', 'Google Calendar disconnected.');

        expect(GoogleAccount::count())->toBe(0)
            ->and(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com/revoke')))->toHaveCount(1)
            ->and(googleCalls('DELETE'))->toHaveCount(0);
    });

    test('disconnecting still works when Google cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('offline'));
        $user = googleUser();

        $this->actingAs($user)->delete('/integrations/google')->assertRedirect('/integrations/google');

        expect(GoogleAccount::count())->toBe(0);
    });
});

describe('showing Google events on the planner calendar', function () {
    function googleEvents(): array
    {
        return [
            ['id' => 'e1', 'summary' => 'Dentist', 'start' => ['dateTime' => '2026-10-07T15:00:00-04:00'], 'end' => ['dateTime' => '2026-10-07T16:00:00-04:00'], 'htmlLink' => 'https://calendar.google.com/e1'],
            ['id' => 'e2', 'summary' => 'Holiday', 'start' => ['date' => '2026-10-08'], 'end' => ['date' => '2026-10-09']],
            ['id' => 'e3', 'summary' => 'Study', 'start' => ['dateTime' => '2026-10-07T18:00:00Z'], 'end' => ['dateTime' => '2026-10-07T19:00:00Z'], 'extendedProperties' => ['private' => ['planner' => 'session']]],
            ['id' => 'e4', 'summary' => 'Called off', 'status' => 'cancelled', 'start' => ['dateTime' => '2026-10-07T20:00:00Z'], 'end' => ['dateTime' => '2026-10-07T21:00:00Z']],
            ['id' => 'e5', 'start' => ['dateTime' => '2026-10-09T14:00:00Z'], 'end' => ['dateTime' => '2026-10-09T15:00:00Z']],
        ];
    }

    test('events are shown without the ones this app created or that were cancelled', function () {
        fakeGoogle(events: googleEvents());
        $user = googleUser(['import_calendar_ids' => ['primary-id']]);

        $this->actingAs($user)->get('/?week=2026-10-05&timezone=America/New_York')
            ->assertInertia(fn (Assert $page) => $page
                ->where('google.connected', true)
                ->where('google.needsReconnect', false)
                ->loadDeferredProps(fn (Assert $loaded) => $loaded
                    ->has('googleEvents', 3)
                    ->where('googleEvents.0.title', 'Dentist')
                    ->where('googleEvents.0.starts_at', '2026-10-07T19:00:00+00:00')
                    ->where('googleEvents.0.ends_at', '2026-10-07T20:00:00+00:00')
                    ->where('googleEvents.0.all_day', false)
                    ->where('googleEvents.0.calendar', 'Me')
                    ->where('googleEvents.0.html_link', 'https://calendar.google.com/e1')
                    ->where('googleEvents.1.title', 'Holiday')
                    ->where('googleEvents.1.all_day', true)
                    ->where('googleEvents.1.start_date', '2026-10-08')
                    ->where('googleEvents.1.end_date', '2026-10-09')
                    ->where('googleEvents.2.title', '(No title)')));
    });

    test('events from every chosen calendar are shown', function () {
        fakeGoogle(events: [['id' => 'e1', 'summary' => 'Dentist', 'start' => ['dateTime' => '2026-10-07T15:00:00Z'], 'end' => ['dateTime' => '2026-10-07T16:00:00Z']]]);
        $user = googleUser(['import_calendar_ids' => ['primary-id', 'holidays']]);

        $this->actingAs($user)->get('/?week=2026-10-05&timezone=America/New_York')
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $loaded) => $loaded->has('googleEvents', 2)));

        expect(googleCalls('GET', '/calendars/primary-id/events'))->toHaveCount(1)
            ->and(googleCalls('GET', '/calendars/holidays/events'))->toHaveCount(1);
    });

    test('Google is asked for the visible week only and the answer is cached briefly', function () {
        fakeGoogle(events: googleEvents());
        $user = googleUser(['import_calendar_ids' => ['primary-id']]);

        foreach ([1, 2] as $visit) {
            $this->actingAs($user)->get('/?week=2026-10-05&timezone=America/New_York')
                ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $loaded) => $loaded->has('googleEvents', 3)));
        }

        $events = googleCalls('GET', '/calendars/primary-id/events');

        expect($events)->toHaveCount(1)
            ->and($events->first()->url())->toContain('timeMin=2026-10-05T04%3A00%3A00%2B00%3A00')
            ->and($events->first()->url())->toContain('singleEvents=true');
    });

    test('nothing is requested for a user without Google, or with importing switched off', function () {
        fakeGoogle(events: googleEvents());

        $this->actingAs(User::factory()->create())->get('/?week=2026-10-05')
            ->assertInertia(fn (Assert $page) => $page->where('google.connected', false)->where('googleEvents', []));

        $this->actingAs(googleUser(['import_calendar_ids' => []]))->get('/?week=2026-10-05')
            ->assertInertia(fn (Assert $page) => $page->where('google.connected', true)->where('googleEvents', []));

        expect(googleCalls('GET', '/events'))->toHaveCount(0);
    });

    test('a connection that needs renewing shows no events and says so', function () {
        fakeGoogle(events: googleEvents());

        $this->actingAs(googleUser(['needs_reconnect' => true]))->get('/?week=2026-10-05')
            ->assertInertia(fn (Assert $page) => $page->where('google.needsReconnect', true)->where('googleEvents', []));
    });

    test('a Google failure never breaks the planner', function () {
        fakeGoogle(['GET /events' => 500]);
        $user = googleUser(['import_calendar_ids' => ['primary-id']]);

        $this->actingAs($user)->get('/?week=2026-10-05&timezone=America/New_York')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $loaded) => $loaded->has('googleEvents', 0)));
    });
});
