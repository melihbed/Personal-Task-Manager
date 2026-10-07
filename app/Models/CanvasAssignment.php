<?php

namespace App\Models;

use Database\Factories\CanvasAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanvasAssignment extends Model
{
    /** @use HasFactory<CanvasAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'canvas_id', 'name', 'kind', 'due_at', 'points_possible', 'html_url',
        'submitted', 'missing', 'late', 'score',
        'task_id', 'task_created', 'task_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'points_possible' => 'float',
            'score' => 'float',
            'submitted' => 'boolean',
            'missing' => 'boolean',
            'late' => 'boolean',
            'task_created' => 'boolean',
            'task_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CanvasCourse::class, 'canvas_course_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Work still to do: not submitted in Canvas, and its task (if any) not completed. */
    public function scopeOpen(Builder $query): void
    {
        $query->where('submitted', false)
            ->where(fn (Builder $inner) => $inner->whereNull('task_id')->orWhereDoesntHave('task', fn (Builder $task) => $task->whereNotNull('completed_at')));
    }

    /** Whether a planner task was made from a Canvas assignment, so its deadline must not be pushed to Google. */
    public static function hasTask(int $userId, int $taskId): bool
    {
        return static::where('user_id', $userId)->where('task_id', $taskId)->exists();
    }
}
