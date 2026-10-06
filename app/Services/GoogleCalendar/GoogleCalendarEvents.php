<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The user's Google events for a time window, shaped for the planner calendar. Events this app created
 * are left out (they are already shown as planner items). Results are cached briefly, and a failing
 * Google call never breaks the page.
 */
class GoogleCalendarEvents
{
    private const CACHE_SECONDS = 60;

    /**
     * @return list<array{id: string, title: string, calendar: string, color: string|null, all_day: bool, starts_at: string|null, ends_at: string|null, start_date: string|null, end_date: string|null, html_link: string|null}>
     */
    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $account = $user->googleAccount;

        if ($account === null || $account->needs_reconnect || empty($account->import_calendar_ids)) {
            return [];
        }

        $client = new GoogleCalendarClient($account);
        $calendars = $this->calendars($account, $client);
        $events = [];

        foreach ($account->import_calendar_ids as $calendarId) {
            $key = "google-events:{$account->id}:".sha1($calendarId).":{$from->toDateString()}:{$to->toDateString()}";

            try {
                $items = Cache::remember($key, self::CACHE_SECONDS, fn () => $client->events($calendarId, $from, $to));
            } catch (GoogleApiException|GoogleReconnectRequired|ConnectionException $exception) {
                Log::warning('Could not load Google events.', ['calendar' => $calendarId, 'error' => $exception->getMessage()]);

                continue;
            }

            foreach ($items as $item) {
                $event = $this->event($item, $calendarId, $calendars[$calendarId] ?? null);

                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        usort($events, fn (array $a, array $b) => ($a['starts_at'] ?? $a['start_date']) <=> ($b['starts_at'] ?? $b['start_date']));

        return $events;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{name: string, color: string|null}|null  $calendar
     * @return array<string, mixed>|null
     */
    private function event(array $item, string $calendarId, ?array $calendar): ?array
    {
        if (($item['status'] ?? '') === 'cancelled' || isset($item['extendedProperties']['private'][GoogleEventMapper::MARKER])) {
            return null;
        }

        $allDay = isset($item['start']['date']);

        return [
            'id' => $calendarId.'|'.$item['id'],
            'title' => $item['summary'] ?? '(No title)',
            'calendar' => $calendar['name'] ?? $calendarId,
            'color' => $calendar['color'] ?? null,
            'all_day' => $allDay,
            'starts_at' => $allDay ? null : CarbonImmutable::parse($item['start']['dateTime'])->utc()->toIso8601String(),
            'ends_at' => $allDay ? null : CarbonImmutable::parse($item['end']['dateTime'])->utc()->toIso8601String(),
            'start_date' => $allDay ? $item['start']['date'] : null,
            // Google's all-day end date is exclusive.
            'end_date' => $allDay ? $item['end']['date'] : null,
            'html_link' => $item['htmlLink'] ?? null,
        ];
    }

    /**
     * Calendar names and colors, by id.
     *
     * @return array<string, array{name: string, color: string|null}>
     */
    private function calendars(GoogleAccount $account, GoogleCalendarClient $client): array
    {
        try {
            $list = Cache::remember("google-calendars:{$account->id}", 300, fn () => $client->calendars());
        } catch (GoogleApiException|GoogleReconnectRequired|ConnectionException) {
            return [];
        }

        $byId = [];

        foreach ($list as $calendar) {
            $byId[$calendar['id']] = ['name' => $calendar['name'], 'color' => $calendar['color']];

            if ($calendar['primary']) {
                $byId['primary'] = $byId[$calendar['id']];
            }
        }

        return $byId;
    }
}
