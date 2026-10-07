<?php

namespace App\Models;

use App\Observers\CalendarEventObserver;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CalendarEventObserver::class])]
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory;

    protected $fillable = ['title', 'location', 'notes', 'all_day', 'starts_at', 'ends_at', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'all_day' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responsibility(): BelongsTo
    {
        return $this->belongsTo(Responsibility::class);
    }

    /**
     * The shape the planner draws: a timed event as two instants, an all-day event as its first and last day (inclusive).
     *
     * @return array<string, mixed>
     */
    public function toPlanner(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'location' => $this->location,
            'notes' => $this->notes,
            'responsibility_id' => $this->responsibility_id,
            'all_day' => $this->all_day,
            'starts_at' => $this->starts_at?->utc()->toIso8601String(),
            'ends_at' => $this->ends_at?->utc()->toIso8601String(),
            'start_date' => $this->starts_on?->toDateString(),
            'end_date' => $this->ends_on?->toDateString(),
        ];
    }
}
