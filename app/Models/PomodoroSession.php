<?php

namespace App\Models;

use Database\Factories\PomodoroSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PomodoroSession extends Model
{
    /** @use HasFactory<PomodoroSessionFactory> */
    use HasFactory;

    public const FOCUS = 'focus';

    public const SHORT_BREAK = 'short_break';

    public const LONG_BREAK = 'long_break';

    protected $fillable = ['kind', 'planned_seconds', 'started_at', 'paused_at', 'paused_seconds', 'ended_at', 'status'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'paused_at' => 'datetime', 'ended_at' => 'datetime', 'planned_seconds' => 'integer', 'paused_seconds' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Whether this is a running or paused session, the one the timer is showing. */
    public function isActive(): bool
    {
        return in_array($this->status, ['running', 'paused'], true);
    }
}
