<?php

namespace App\Models;

use App\Observers\TaskObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy([TaskObserver::class])]
class Task extends Model
{
    protected $fillable = ['title', 'notes', 'due_at', 'due_has_time', 'priority', 'estimate_minutes'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'due_has_time' => 'boolean', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Responsibility, $this> */
    public function responsibility(): BelongsTo
    {
        return $this->belongsTo(Responsibility::class);
    }

    /** @return HasMany<CalendarSession, $this> */
    public function calendarSessions(): HasMany
    {
        return $this->hasMany(CalendarSession::class);
    }

    /**
     * The Canvas assignment this task was made from, if any.
     *
     * @return HasOne<CanvasAssignment, $this>
     */
    public function canvasAssignment(): HasOne
    {
        return $this->hasOne(CanvasAssignment::class);
    }

    /** @return HasMany<PomodoroSession, $this> */
    public function pomodoroSessions(): HasMany
    {
        return $this->hasMany(PomodoroSession::class);
    }
}
