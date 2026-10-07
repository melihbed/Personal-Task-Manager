<?php

namespace App\Services\GoogleCalendar;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;

/**
 * Moves the user's Google calendar into the app, once. Every upcoming event becomes an event of the app's own, and a
 * repeating series that can be followed becomes a routine. Events already copied, hidden, or added to Google by an older
 * version of this app are left out, so running it again only brings in what is new. Nothing in Google is changed.
 */
class GoogleMigration
{
    /** How far ahead events are brought in. */
    private const MONTHS_AHEAD = 12;

    /** A repeating series the app cannot follow is brought in as single events, but only this many of them. */
    private const MAX_LOOSE_INSTANCES = 60;

    private const MAX_PAGES = 20;

    private const MAX_NOTES = 5;

    public function __construct(private readonly GoogleEventImporter $importer) {}

    /**
     * @return array{events: int, routines: int, notes: list<string>}
     *
     * @throws ImportNotPossible when there is nothing to move, or Google cannot be reached
     */
    public function run(User $user, string $timezone): array
    {
        $account = $user->googleAccount;

        if ($account === null || $account->needs_reconnect) {
            throw new ImportNotPossible('Connect Google Calendar first.');
        }

        $calendarIds = $account->import_calendar_ids ?? [];

        if ($calendarIds === []) {
            throw new ImportNotPossible('Choose the calendars to move over first.');
        }

        $client = new GoogleCalendarClient($account);
        $from = CarbonImmutable::now($timezone)->startOfDay();
        $to = $from->addMonths(self::MONTHS_AHEAD);
        $result = ['events' => 0, 'routines' => 0, 'notes' => []];

        try {
            foreach ($calendarIds as $calendarId) {
                $this->calendar($user, $client, $calendarId, $client->events($calendarId, $from, $to, self::MAX_PAGES), $timezone, $result);
            }
        } catch (GoogleApiException|GoogleReconnectRequired|ConnectionException $exception) {
            throw new ImportNotPossible('Google could not be reached, so only part of your calendar was moved. Try again to bring in the rest.');
        }

        // Everything is in the app now, so the preview of Google events has nothing left to show.
        $account->update(['import_calendar_ids' => []]);

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{events: int, routines: int, notes: list<string>}  $result
     */
    private function calendar(User $user, GoogleCalendarClient $client, string $calendarId, array $items, string $timezone, array &$result): void
    {
        $known = $user->googleEventImports()->where('google_calendar_id', $calendarId)->pluck('google_event_id')->flip()->all();
        $series = [];

        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'cancelled' || isset($item['extendedProperties']['private']['planner']) || isset($known[$item['id']])) {
                continue;
            }

            if (isset($item['recurringEventId'])) {
                $series[$item['recurringEventId']][] = $item;

                continue;
            }

            $this->importer->asEvent($user, $calendarId, $item, null);
            $result['events']++;
        }

        foreach ($series as $seriesId => $instances) {
            if (isset($known[$seriesId])) {
                continue;
            }

            try {
                $this->importer->asRoutine($user, $calendarId, $client->event($calendarId, $seriesId), null, $timezone);
                $result['routines']++;

                continue;
            } catch (ImportNotPossible $reason) {
                $title = trim((string) ($instances[0]['summary'] ?? '')) ?: '(No title)';

                if (count($result['notes']) < self::MAX_NOTES) {
                    $result['notes'][] = "“{$title}” repeats in a way routines cannot follow, so its next ".min(count($instances), self::MAX_LOOSE_INSTANCES).' events came in as single events.';
                }
            }

            foreach (array_slice($instances, 0, self::MAX_LOOSE_INSTANCES) as $instance) {
                $this->importer->asEvent($user, $calendarId, $instance, null);
                $result['events']++;
            }
        }
    }
}
