<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Responsibility extends Model
{
    protected $fillable = ['name', 'description', 'color'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function tasks(): HasMany { return $this->hasMany(Task::class); }
}
