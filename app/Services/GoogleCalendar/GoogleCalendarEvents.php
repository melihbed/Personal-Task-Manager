<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleAccount;
use App\Models\GoogleEventImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The user's Google events for a time window, shaped for the planner calendar. Events this app created
 * are left out (they are already shown as planner items). Results are cached briefly, and a failing
 * Google call never breaks the page.
 */
class GoogleCalendarEvents
{
    private const CACHE_SECONDS = 60;

    /** The private property an older version of this app put on events it added to Google, so they never come back as new events. */
    private const MARKER = 'planner';

    /**
     * @return list<array<string, mixed>>
     */
    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $account = $user->googleAccount;

        if ($account === null || $account->needs_reconnect || empty($account->import_calendar_ids)) {
            return [];
        }

        $client = new GoogleCalendarClient($account);
        $calendars = $this->calendars($account, $client);
        $imported = $this->imported($user);
        $events = [];

        foreach ($account->import_calendar_ids as $calendarId) {
            $key = "google-events:{$account->id}:v".self::version($account).':'.sha1($calendarId).":{$from->toDateString()}:{$to->toDateString()}";

            try {
                $items = Cache::remember($key, self::CACHE_SECONDS, fn () => $client->events($calendarId, $from, $to));
            } catch (GoogleApiException|GoogleReconnectRequired|ConnectionException $exception) {
                Log::warning('Could not load Google events.', ['calendar' => $calendarId, 'error' => $exception->getMessage()]);

                continue;
            }

            foreach ($items as $item) {
                $event = $this->event($item, $calendarId, $calendars[$calendarId] ?? null, $imported);

                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        usort($events, fn (array $a, array $b) => ($a['starts_at'] ?? $a['start_date']) <=> ($b['starts_at'] ?? $b['start_date']));

        return $events;
    }

    /** Drop every cached week for this account, so a change made through the app shows straight away. */
    public static function forget(GoogleAccount $account): void
    {
        Cache::forever("google-events-version:{$account->id}", self::version($account) + 1);
    }

    private static function version(GoogleAccount $account): int
    {
        return (int) Cache::get("google-events-version:{$account->id}", 0);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{name: string, color: string|null}|null  $calendar
     * @param  array<string, true>  $imported  keys of events already copied into the planner
     * @return array<string, mixed>|null
     */
    private function event(array $item, string $calendarId, ?array $calendar, array $imported): ?array
    {
        if (($item['status'] ?? '') === 'cancelled' || isset($item['extendedProperties']['private'][self::MARKER])) {
            return null;
        }

        // Already copied into the planner, as itself or as the repeating series it belongs to.
        if (isset($imported[$calendarId.'|'.$item['id']]) || (isset($item['recurringEventId']) && isset($imported[$calendarId.'|'.$item['recurringEventId']]))) {
            return null;
        }

        $allDay = isset($item['start']['date']);

        return [
            'id' => $calendarId.'|'.$item['id'],
            'calendar_id' => $calendarId,
            'event_id' => $item['id'],
            'recurring_event_id' => $item['recurringEventId'] ?? null,
            'location' => isset($item['location']) ? Str::limit(trim($item['location']), 200) : null,
            'description' => $this->plainText($item['description'] ?? null),
            'guests' => count($item['attendees'] ?? []),
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

    /**
     * Keys ("calendar|event") of the Google events the user has copied into the planner.
     *
     * @return array<string, true>
     */
    private function imported(User $user): array
    {
        return $user->googleEventImports()->get(['google_calendar_id', 'google_event_id'])
            ->mapWithKeys(fn (GoogleEventImport $import) => [$import->google_calendar_id.'|'.$import->google_event_id => true])
            ->all();
    }

    /** Google descriptions can contain HTML; show them as short plain text. */
    private function plainText(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li)>/i'], "\n", $html);

        return Str::limit(trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags((string) $text)))), 600);
    }
}
