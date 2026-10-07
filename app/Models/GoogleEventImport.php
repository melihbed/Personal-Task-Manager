<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleEventImport extends Model
{
    /** kind is task, session or routine for a copy in the planner, or hidden for an event hidden from the calendar. */
    protected $fillable = ['google_calendar_id', 'google_event_id', 'kind', 'item_id', 'label'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether a planner item was copied from Google, and so must not be pushed back. */
    public static function isImported(int $userId, string $kind, int $itemId): bool
    {
        return static::where('user_id', $userId)->where('kind', $kind)->where('item_id', $itemId)->exists();
    }

    /** Forget where a deleted planner item came from, so the Google event shows on the calendar again. */
    public static function forget(int $userId, string $kind, int $itemId): void
    {
        static::where('user_id', $userId)->where('kind', $kind)->where('item_id', $itemId)->delete();
    }
}
