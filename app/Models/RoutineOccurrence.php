<?php

namespace App\Models;

use App\Observers\RoutineOccurrenceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([RoutineOccurrenceObserver::class])]
class RoutineOccurrence extends Model
{
    protected $fillable = ['occurs_on', 'skipped', 'completed_at', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'occurs_on' => 'date:Y-m-d',
            'skipped' => 'boolean',
            'completed_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
