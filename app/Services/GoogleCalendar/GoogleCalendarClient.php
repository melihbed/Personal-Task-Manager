<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleAccount;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A small client for the parts of the Google Calendar API this app uses, authorized as one account.
 * The access token is refreshed when it is about to expire, or once if Google rejects it.
 */
class GoogleCalendarClient
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const EVENT_FIELDS = 'items(id,summary,description,location,status,start,end,htmlLink,recurringEventId,attendees(self),transparency,extendedProperties/private),nextPageToken';

    private const SINGLE_EVENT_FIELDS = 'id,summary,description,location,status,start,end,htmlLink,recurrence,recurringEventId';

    public function __construct(private readonly GoogleAccount $account) {}

    /**
     * The calendars the user can see.
     *
     * @return list<array{id: string, name: string, primary: bool, writable: bool, color: string|null}>
     */
    public function calendars(): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get('/users/me/calendarList', [
            'minAccessRole' => 'reader',
            'fields' => 'items(id,summary,summaryOverride,primary,accessRole,backgroundColor)',
        ]));

        return collect($response->json('items', []))->map(fn (array $calendar) => [
            'id' => $calendar['id'],
            'name' => $calendar['summaryOverride'] ?? $calendar['summary'] ?? $calendar['id'],
            'primary' => (bool) ($calendar['primary'] ?? false),
            'writable' => in_array($calendar['accessRole'] ?? 'reader', ['owner', 'writer'], true),
            'color' => $calendar['backgroundColor'] ?? null,
        ])->all();
    }

    /**
     * Events overlapping [$from, $to), with recurring events expanded into single instances.
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $calendarId, CarbonInterface $from, CarbonInterface $to): array
    {
        $items = [];
        $pageToken = null;

        for ($page = 0; $page < 4; $page++) {
            $response = $this->send(fn (PendingRequest $request) => $request->get('/calendars/'.rawurlencode($calendarId).'/events', array_filter([
                'timeMin' => $from->utc()->toIso8601String(),
                'timeMax' => $to->utc()->toIso8601String(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
                'fields' => self::EVENT_FIELDS,
                'pageToken' => $pageToken,
            ])));

            $items = [...$items, ...$response->json('items', [])];
            $pageToken = $response->json('nextPageToken');

            if ($pageToken === null) {
                break;
            }
        }

        return $items;
    }

    /**
     * One event, with its recurrence rule when it is the master of a repeating series.
     *
     * @return array<string, mixed>
     */
    public function event(string $calendarId, string $eventId): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get('/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId), [
            'fields' => self::SINGLE_EVENT_FIELDS,
        ]))->json();
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function insert(string $calendarId, array $event): array
    {
        return $this->send(fn (PendingRequest $request) => $request->post('/calendars/'.rawurlencode($calendarId).'/events', $event))->json();
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function patch(string $calendarId, string $eventId, array $event): array
    {
        return $this->send(fn (PendingRequest $request) => $request->patch('/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId), $event))->json();
    }

    /** Deletes an event. An event that is already gone counts as deleted. */
    public function delete(string $calendarId, string $eventId): void
    {
        try {
            $this->send(fn (PendingRequest $request) => $request->delete('/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId)));
        } catch (GoogleApiException $exception) {
            if (! $exception->isGone()) {
                throw $exception;
            }
        }
    }

    /** Stops the app's access. Best effort; the local copy of the tokens is removed either way. */
    public function revoke(): void
    {
        Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $this->account->refresh_token ?? $this->account->access_token]);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $response = $call($this->request());

        if ($response->status() === 401) {
            $this->refresh();
            $response = $call($this->request());
        }

        if ($response->failed()) {
            throw new GoogleApiException($response->json('error.message') ?? 'Google Calendar request failed.', $response->status());
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        if ($this->account->expires_at !== null && $this->account->expires_at->subMinute()->isPast()) {
            $this->refresh();
        }

        return Http::baseUrl(self::API)->withToken($this->account->access_token)->acceptJson()->timeout(20);
    }

    private function refresh(): void
    {
        if ($this->account->refresh_token === null) {
            $this->requireReconnect();
        }

        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $this->account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->status() === 400 || $response->status() === 401) {
            $this->requireReconnect();
        }

        if ($response->failed()) {
            throw new GoogleApiException('Could not refresh the Google token.', $response->status());
        }

        $this->account->forceFill([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ])->save();
    }

    private function requireReconnect(): never
    {
        $this->account->forceFill(['needs_reconnect' => true])->save();

        throw new GoogleReconnectRequired('Google Calendar needs to be connected again.');
    }
}
