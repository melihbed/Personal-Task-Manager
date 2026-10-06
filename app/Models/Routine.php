<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RoutineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routine extends Model
{
    /** @use HasFactory<RoutineFactory> */
    use HasFactory;

    protected $fillable = ['title', 'days', 'start_time', 'duration_minutes', 'timezone', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'duration_minutes' => 'integer',
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

    public function occurrences(): HasMany
    {
        return $this->hasMany(RoutineOccurrence::class);
    }

    /**
     * Whether the rule produces an occurrence on this date (Y-m-d, in the routine's timezone).
     */
    public function occursOn(string $date): bool
    {
        $weekday = CarbonImmutable::parse($date, $this->timezone)->isoWeekday();

        return in_array($weekday, $this->days, true)
            && $date >= $this->starts_on->toDateString()
            && ($this->ends_on === null || $date <= $this->ends_on->toDateString());
    }

    /**
     * The occurrences overlapping [$from, $to), with skips, moves and completion applied.
     *
     * Sorted by start time. Expects the `occurrences` relation to be loaded for a window covering the range. The wall-clock
     * start is resolved in the routine's own timezone, so 8:00 AM stays 8:00 AM across daylight saving.
     *
     * @return list<array{routine_id: int, occurs_on: string, title: string, starts_at: string, ends_at: string, completed: bool, moved: bool}>
     */
    public function occurrencesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $overrides = $this->occurrences->keyBy(fn (RoutineOccurrence $occurrence) => $occurrence->occurs_on->toDateString());
        $found = [];
        $seen = [];

        // One day of margin before the window, because a block can start the day before and run into it.
        $last = $to->setTimezone($this->timezone)->startOfDay();

        for ($day = $from->setTimezone($this->timezone)->startOfDay()->subDay(); $day <= $last; $day = $day->addDay()) {
            $date = $day->toDateString();

            if (! $this->occursOn($date)) {
                continue;
            }

            $seen[$date] = true;
            $start = CarbonImmutable::parse("{$date} {$this->start_time}", $this->timezone)->utc();
            $found[] = $this->occurrence($date, $start, $start->addMinutes($this->duration_minutes), $overrides->get($date));
        }

        // An occurrence moved into the window from a date outside it.
        foreach ($overrides as $date => $override) {
            if (isset($seen[$date]) || $override->starts_at === null || ! $this->occursOn($date)) {
                continue;
            }

            $found[] = $this->occurrence($date, $override->starts_at, $override->ends_at, $override);
        }

        $inWindow = array_filter(
            array_filter($found),
            fn (array $item) => $item['starts_at'] < $to->utc()->toIso8601String()
                && $item['ends_at'] > $from->utc()->toIso8601String(),
        );

        usort($inWindow, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);

        return $inWindow;
    }

    /**
     * @return array{routine_id: int, occurs_on: string, title: string, starts_at: string, ends_at: string, completed: bool, moved: bool}|null
     */
    private function occurrence(string $date, CarbonImmutable $start, CarbonImmutable $end, ?RoutineOccurrence $override): ?array
    {
        if ($override?->skipped) {
            return null;
        }

        $start = $override?->starts_at ?? $start;
        $end = $override?->ends_at ?? $end;

        return [
            'routine_id' => $this->id,
            'occurs_on' => $date,
            'title' => $this->title,
            'starts_at' => CarbonImmutable::instance($start)->utc()->toIso8601String(),
            'ends_at' => CarbonImmutable::instance($end)->utc()->toIso8601String(),
            'completed' => $override?->completed_at !== null,
            'moved' => $override?->starts_at !== null,
        ];
    }
}
