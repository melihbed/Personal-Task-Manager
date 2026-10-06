<?php

namespace App\Services\GoogleCalendar;

use App\Jobs\SyncGoogleItem;
use App\Models\CalendarSession;
use App\Models\GoogleAccount;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;

/** Queues Google syncs, and only for users who have connected a calendar. */
class GoogleSyncDispatcher
{
    /**
     * @param  'session'|'deadline'|'routine'  $kind
     */
    public static function item(int $userId, string $kind, int $itemId): void
    {
        if (! self::connected($userId)) {
            return;
        }

        SyncGoogleItem::dispatch($userId, $kind, $itemId)->afterCommit();
    }

    /**
     * Queues a sync for every item of the given kinds, including items whose Google event exists but whose
     * planner item is gone. Each job decides whether to create, update or remove.
     *
     * @param  list<'session'|'deadline'|'routine'>  $kinds
     */
    public static function all(User $user, array $kinds): void
    {
        if (! self::connected($user->id)) {
            return;
        }

        $local = [
            'session' => fn () => CalendarSession::where('user_id', $user->id)->pluck('id'),
            'deadline' => fn () => Task::where('user_id', $user->id)->whereNotNull('due_at')->pluck('id'),
            'routine' => fn () => Routine::where('user_id', $user->id)->pluck('id'),
        ];

        foreach ($kinds as $kind) {
            $linked = $user->googleEventLinks()->where('kind', $kind)->pluck('item_id');

            foreach ($local[$kind]()->merge($linked)->unique() as $id) {
                SyncGoogleItem::dispatch($user->id, $kind, (int) $id);
            }
        }
    }

    private static function connected(int $userId): bool
    {
        return GoogleAccount::where('user_id', $userId)->where('needs_reconnect', false)->whereNotNull('calendar_id')->exists();
    }
}
