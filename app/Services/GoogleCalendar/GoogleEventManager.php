<?php

namespace App\Services\GoogleCalendar;

use App\Models\GoogleAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;

/**
 * Edits and deletes events in the user's Google Calendar. These change the real event in Google. For a repeating
 * event the caller chooses whether the change is for that one event or for the whole series. Google's refusals
 * (a read-only calendar, a guest, a deleted event) become plain messages.
 */
class GoogleEventManager
{
    /**
     * @param  'event'|'series'  $scope
     *
     * @throws EventActionFailed
     */
    public function delete(GoogleAccount $account, string $calendarId, string $eventId, string $scope): void
    {
        $client = new GoogleCalendarClient($account);
        $target = $scope === 'series' ? $this->seriesId($this->read($client, $calendarId, $eventId)) : $eventId;

        $this->run(fn () => $client->delete($calendarId, $target));

        // Anything hidden or copied from the event or series that is now gone no longer needs remembering.
        $account->user->googleEventImports()
            ->where('kind', 'hidden')
            ->where('google_calendar_id', $calendarId)
            ->whereIn('google_event_id', array_unique([$eventId, $target]))
            ->delete();

        GoogleCalendarEvents::forget($account);
    }

    /**
     * Changes an event's title and time. For one event, the time is set exactly. For a whole series, the title is
     * changed and, for a timed series, the time of day and length; the days the series falls on stay the same.
     *
     * @param  'event'|'series'  $scope
     * @param  array{title: string, all_day: bool, timezone: string, starts_at?: string|null, ends_at?: string|null, start_date?: string|null, end_date?: string|null}  $changes
     *
     * @throws EventActionFailed
     */
    public function update(GoogleAccount $account, string $calendarId, string $eventId, string $scope, array $changes): void
    {
        $client = new GoogleCalendarClient($account);
        $event = $this->read($client, $calendarId, $eventId);

        if (isset($event['start']['date']) !== $changes['all_day']) {
            throw new EventActionFailed('This event was changed in Google Calendar. Close this and open it again.');
        }

        if ($scope === 'series') {
            $seriesId = $this->seriesId($event);
            $payload = $this->seriesPayload($client, $calendarId, $seriesId, $changes);
            $target = $seriesId;
        } else {
            $payload = ['summary' => $changes['title']] + $this->eventTimes($changes);
            $target = $eventId;
        }

        $this->run(fn () => $client->patch($calendarId, $target, $payload));

        GoogleCalendarEvents::forget($account);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function seriesPayload(GoogleCalendarClient $client, string $calendarId, string $seriesId, array $changes): array
    {
        $payload = ['summary' => $changes['title']];

        if ($changes['all_day']) {
            return $payload;
        }

        $series = $this->read($client, $calendarId, $seriesId);
        $timezone = $series['start']['timeZone'] ?? $changes['timezone'];
        $first = CarbonImmutable::parse($series['start']['dateTime'])->setTimezone($timezone);
        $edited = CarbonImmutable::parse($changes['starts_at'])->setTimezone($timezone);
        $minutes = (int) CarbonImmutable::parse($changes['starts_at'])->diffInMinutes(CarbonImmutable::parse($changes['ends_at']));

        // Keep the series' first day; take the new time of day and length.
        $start = CarbonImmutable::parse($first->toDateString().' '.$edited->format('H:i:s'), $timezone);

        return $payload + [
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
            'end' => ['dateTime' => $start->addMinutes($minutes)->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function eventTimes(array $changes): array
    {
        if ($changes['all_day']) {
            // Google's all-day end date is exclusive.
            return [
                'start' => ['date' => $changes['start_date']],
                'end' => ['date' => CarbonImmutable::parse($changes['end_date'])->addDay()->toDateString()],
            ];
        }

        $timezone = $changes['timezone'];

        return [
            'start' => ['dateTime' => CarbonImmutable::parse($changes['starts_at'])->setTimezone($timezone)->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
            'end' => ['dateTime' => CarbonImmutable::parse($changes['ends_at'])->setTimezone($timezone)->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function seriesId(array $event): string
    {
        return $event['recurringEventId'] ?? throw new EventActionFailed('This event does not repeat, so there is no series.');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(GoogleCalendarClient $client, string $calendarId, string $eventId): array
    {
        $event = $this->run(fn () => $client->event($calendarId, $eventId));

        if (($event['status'] ?? '') === 'cancelled') {
            throw new EventActionFailed('That event is no longer in Google Calendar.');
        }

        return $event;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function run(callable $call): mixed
    {
        try {
            return $call();
        } catch (GoogleApiException $exception) {
            throw new EventActionFailed(match (true) {
                $exception->isGone() => 'That event is no longer in Google Calendar.',
                $exception->status === 403 => 'Google does not let you change this event. Its calendar may be read-only, or you may only be a guest.',
                default => 'Google Calendar could not do that. Please try again.',
            });
        } catch (GoogleReconnectRequired|ConnectionException) {
            throw new EventActionFailed('Could not reach Google Calendar. Please try again.');
        }
    }
}
