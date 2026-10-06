<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleAccount extends Model
{
    protected $fillable = [
        'email', 'access_token', 'refresh_token', 'expires_at', 'needs_reconnect',
        'calendar_id', 'calendar_name', 'import_calendar_ids',
        'push_sessions', 'push_routines', 'push_deadlines',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'needs_reconnect' => 'boolean',
            'import_calendar_ids' => 'array',
            'push_sessions' => 'boolean',
            'push_routines' => 'boolean',
            'push_deadlines' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether the app has Google OAuth credentials, so the Connect button can work. */
    public static function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /**
     * Where the user stands with Google Calendar: not_configured (no OAuth credentials in the app),
     * not_connected, needs_reconnect (Google rejected the saved authorization) or connected.
     */
    public static function stateOf(?self $account): string
    {
        return match (true) {
            ! self::isConfigured() => 'not_configured',
            $account === null => 'not_connected',
            $account->needs_reconnect => 'needs_reconnect',
            default => 'connected',
        };
    }

    /**
     * Whether planner items are being sent to Google: a calendar is chosen, the connection works, and
     * the toggle for this kind of item is on.
     */
    public function pushes(string $kind): bool
    {
        if ($this->calendar_id === null || $this->needs_reconnect) {
            return false;
        }

        return match ($kind) {
            'session' => $this->push_sessions,
            'routine', 'occurrence' => $this->push_routines,
            'deadline' => $this->push_deadlines,
            default => false,
        };
    }
}
