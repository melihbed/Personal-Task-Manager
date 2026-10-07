<?php

namespace App\Models;

use Database\Factories\PomodoroSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PomodoroSetting extends Model
{
    /** @use HasFactory<PomodoroSettingFactory> */
    use HasFactory;

    /** What applies until the user changes it: the "deep work" rhythm. */
    public const DEFAULTS = ['focus_minutes' => 50, 'short_break_minutes' => 10, 'long_break_minutes' => 30, 'rounds_before_long' => 3, 'sound_enabled' => true];

    protected $fillable = ['focus_minutes', 'short_break_minutes', 'long_break_minutes', 'rounds_before_long', 'sound_enabled'];

    protected function casts(): array
    {
        return ['focus_minutes' => 'integer', 'short_break_minutes' => 'integer', 'long_break_minutes' => 'integer', 'rounds_before_long' => 'integer', 'sound_enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
