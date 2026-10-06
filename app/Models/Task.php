<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = ['title', 'notes', 'due_at', 'priority'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function responsibility(): BelongsTo { return $this->belongsTo(Responsibility::class); }
    public function calendarSessions(): HasMany { return $this->hasMany(CalendarSession::class); }
}
