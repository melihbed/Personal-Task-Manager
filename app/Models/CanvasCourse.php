<?php

namespace App\Models;

use Database\Factories\CanvasCourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanvasCourse extends Model
{
    /** @use HasFactory<CanvasCourseFactory> */
    use HasFactory;

    protected $fillable = ['canvas_id', 'name', 'course_code', 'term', 'tracked'];

    protected function casts(): array
    {
        return ['tracked' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CanvasAssignment::class);
    }
}
