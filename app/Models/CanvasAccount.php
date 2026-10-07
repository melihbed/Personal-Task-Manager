<?php

namespace App\Models;

use Database\Factories\CanvasAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanvasAccount extends Model
{
    /** @use HasFactory<CanvasAccountFactory> */
    use HasFactory;

    protected $fillable = ['base_url', 'access_token', 'canvas_user_name', 'needs_reconnect', 'last_synced_at', 'last_error', 'responsibility_id'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'needs_reconnect' => 'boolean',
            'last_synced_at' => 'datetime',
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

    /** Where the user stands with Canvas: not_connected, needs_reconnect (Canvas rejected the token) or connected. */
    public static function stateOf(?self $account): string
    {
        return match (true) {
            $account === null => 'not_connected',
            $account->needs_reconnect => 'needs_reconnect',
            default => 'connected',
        };
    }
}
