<?php

namespace App\Services\GoogleCalendar;

use App\Models\CalendarSession;
use App\Models\CanvasAssignment;
use App\Models\GoogleAccount;
use App\Models\GoogleEventImport;
use App\Models\GoogleEventLink;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use InvalidArgumentException;

/**
 * Keeps Google Calendar in step with one planner item at a time. For each item it decides whether the
 * Google event should exist, then creates, patches or deletes it. It is safe to run repeatedly: an item
 * whose event is already up to date is skipped.
 */
class GoogleSync
{
    public function __construct(private readonly GoogleEventMapper $mapper) {}

    /**
     * @param  'session'|'deadline'|'routine'  $kind
     */
    public function sync(User $user, string $kind, int $itemId): void
    {
        $account = $user->googleAccount;

        if ($account === null || $account->needs_reconnect) {
            return;
        }

        $client = new GoogleCalendarClient($account);

        match ($kind) {
            'session' => $this->session($user, $account, $client, $itemId),
            'deadline' => $this->deadline($user, $account, $client, $itemId),
            'routine' => $this->routine($user, $account, $client, $itemId),
            default => throw new InvalidArgumentException("Unknown kind [{$kind}]."),
        };
    }

    /** Deletes every event this app created in Google, and forgets the links. */
    public function removeAll(User $user): void
    {
        $account = $user->googleAccount;

        if ($account === null || $account->needs_reconnect) {
            return;
        }

        $client = new GoogleCalendarClient($account);

        foreach ($user->googleEventLinks()->where('kind', '!=', 'occurrence')->get() as $link) {
            $client->delete($link->google_calendar_id, $link->google_event_id);
            $link->delete();
        }

        // Moved days lived inside their routine's event and went with it.
        $user->googleEventLinks()->delete();
    }

    private function session(User $user, GoogleAccount $account, GoogleCalendarClient $client, int $id): void
    {
        if (GoogleEventImport::isImported($user->id, 'session', $id)) {
            return;
        }

        $session = CalendarSession::where('user_id', $user->id)->with('task.responsibility')->find($id);
        $task = $session?->task;

        if ($session && $task && $this->visible($task->responsibility) && $account->pushes('session')) {
            $this->upsert($client, $account, $user, 'session', $id, $this->mapper->session($session, $task));
        } else {
            $this->remove($client, $user, 'session', $id);
        }
    }

    private function deadline(User $user, GoogleAccount $account, GoogleCalendarClient $client, int $id): void
    {
        if (GoogleEventImport::isImported($user->id, 'task', $id) || CanvasAssignment::hasTask($user->id, $id)) {
            return;
        }

        $task = Task::where('user_id', $user->id)->with('responsibility')->find($id);

        if ($task && $task->due_at !== null && $task->completed_at === null && $this->visible($task->responsibility) && $account->pushes('deadline')) {
            $this->upsert($client, $account, $user, 'deadline', $id, $this->mapper->deadline($task));
        } else {
            $this->remove($client, $user, 'deadline', $id);
        }
    }

    private function routine(User $user, GoogleAccount $account, GoogleCalendarClient $client, int $id): void
    {
        if (GoogleEventImport::isImported($user->id, 'routine', $id)) {
            return;
        }

        $routine = Routine::where('user_id', $user->id)->with(['responsibility', 'occurrences'])->find($id);

        if (! $routine || ! $this->visible($routine->responsibility) || ! $account->pushes('routine')) {
            $user->googleEventLinks()->where('kind', 'occurrence')->where('meta->routine_id', $id)->delete();
            $this->remove($client, $user, 'routine', $id);

            return;
        }

        $skipped = $routine->occurrences->where('skipped', true)->map(fn ($occurrence) => $occurrence->occurs_on->toDateString())->all();

        [$link, $changed] = $this->upsert($client, $account, $user, 'routine', $id, $this->mapper->routine($routine, $skipped));

        // Changing the series can drop the exceptions Google holds, so they are applied again.
        if ($changed) {
            $user->googleEventLinks()->where('kind', 'occurrence')->where('meta->routine_id', $id)->delete();
        }

        $this->syncMovedDays($client, $user, $routine, $link);
    }

    /**
     * Applies each moved day of a routine to its Google instance, and puts back days that are no longer moved.
     */
    private function syncMovedDays(GoogleCalendarClient $client, User $user, Routine $routine, GoogleEventLink $master): void
    {
        $moved = $routine->occurrences->filter(fn ($occurrence) => $occurrence->starts_at !== null && ! $occurrence->skipped);
        $links = $user->googleEventLinks()->where('kind', 'occurrence')->where('meta->routine_id', $routine->id)->get()->keyBy('item_id');

        foreach ($moved as $occurrence) {
            $date = $occurrence->occurs_on->toDateString();
            $instanceId = $this->mapper->instanceId($master->google_event_id, $routine, $date);
            $payload = $this->mapper->movedOccurrence($occurrence);
            $hash = $this->hash([$master->google_calendar_id, $instanceId, $payload]);

            if ($links->get($occurrence->id)?->payload_hash === $hash) {
                continue;
            }

            try {
                $client->patch($master->google_calendar_id, $instanceId, $payload);
            } catch (GoogleApiException $exception) {
                if ($exception->isGone()) {
                    continue;
                }

                throw $exception;
            }

            $user->googleEventLinks()->updateOrCreate(
                ['kind' => 'occurrence', 'item_id' => $occurrence->id],
                ['google_calendar_id' => $master->google_calendar_id, 'google_event_id' => $instanceId, 'payload_hash' => $hash, 'meta' => ['routine_id' => $routine->id, 'date' => $date]],
            );
        }

        foreach ($links as $itemId => $link) {
            if ($moved->contains('id', $itemId)) {
                continue;
            }

            $date = $link->meta['date'] ?? null;
            $skipped = $routine->occurrences->contains(fn ($occurrence) => $occurrence->occurs_on->toDateString() === $date && $occurrence->skipped);

            if ($date !== null && ! $skipped && $routine->occursOn($date)) {
                try {
                    $client->patch($link->google_calendar_id, $link->google_event_id, $this->mapper->usualTimes($routine, $date));
                } catch (GoogleApiException $exception) {
                    if (! $exception->isGone()) {
                        throw $exception;
                    }
                }
            }

            $link->delete();
        }
    }

    /**
     * Creates the Google event, or patches it when its content or calendar changed.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: GoogleEventLink, 1: bool} the link, and whether anything was sent to Google
     */
    private function upsert(GoogleCalendarClient $client, GoogleAccount $account, User $user, string $kind, int $itemId, array $payload): array
    {
        $calendarId = (string) $account->calendar_id;
        $hash = $this->hash([$calendarId, $payload]);
        $link = $user->googleEventLinks()->where('kind', $kind)->where('item_id', $itemId)->first();

        if ($link && $link->google_calendar_id === $calendarId) {
            if ($link->payload_hash === $hash) {
                return [$link, false];
            }

            try {
                $client->patch($calendarId, $link->google_event_id, $payload);
                $link->update(['payload_hash' => $hash]);

                return [$link, true];
            } catch (GoogleApiException $exception) {
                if (! $exception->isGone()) {
                    throw $exception;
                }

                // Someone deleted the event in Google; create it again below.
                $link->delete();
            }
        } elseif ($link) {
            // The target calendar changed: take the event out of the old one.
            $client->delete($link->google_calendar_id, $link->google_event_id);
            $link->delete();
        }

        $created = $client->insert($calendarId, $payload);

        $link = $user->googleEventLinks()->create([
            'kind' => $kind,
            'item_id' => $itemId,
            'google_calendar_id' => $calendarId,
            'google_event_id' => $created['id'],
            'payload_hash' => $hash,
        ]);

        return [$link, true];
    }

    private function remove(GoogleCalendarClient $client, User $user, string $kind, int $itemId): void
    {
        $link = $user->googleEventLinks()->where('kind', $kind)->where('item_id', $itemId)->first();

        if ($link === null) {
            return;
        }

        $client->delete($link->google_calendar_id, $link->google_event_id);
        $link->delete();
    }

    /** Tasks in an archived responsibility are hidden from the planner, so they are not pushed either. */
    private function visible(?object $responsibility): bool
    {
        return $responsibility === null || $responsibility->archived_at === null;
    }

    /**
     * @param  array<mixed>  $content
     */
    private function hash(array $content): string
    {
        return sha1((string) json_encode($content));
    }
}
