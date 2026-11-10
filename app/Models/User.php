<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Responsibility, $this> */
    public function responsibilities(): HasMany
    {
        return $this->hasMany(Responsibility::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Routine, $this> */
    public function routines(): HasMany
    {
        return $this->hasMany(Routine::class);
    }

    /** @return HasOne<GoogleAccount, $this> */
    public function googleAccount(): HasOne
    {
        return $this->hasOne(GoogleAccount::class);
    }

    /** @return HasMany<GoogleEventImport, $this> */
    public function googleEventImports(): HasMany
    {
        return $this->hasMany(GoogleEventImport::class);
    }

    /** @return HasOne<CanvasAccount, $this> */
    public function canvasAccount(): HasOne
    {
        return $this->hasOne(CanvasAccount::class);
    }

    /** @return HasMany<CanvasCourse, $this> */
    public function canvasCourses(): HasMany
    {
        return $this->hasMany(CanvasCourse::class);
    }

    /** @return HasMany<CanvasAssignment, $this> */
    public function canvasAssignments(): HasMany
    {
        return $this->hasMany(CanvasAssignment::class);
    }

    /** @return HasMany<AssistantMessage, $this> */
    public function assistantMessages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }

    /** @return HasMany<PomodoroSession, $this> */
    public function pomodoroSessions(): HasMany
    {
        return $this->hasMany(PomodoroSession::class);
    }

    /** @return HasOne<PomodoroSetting, $this> */
    public function pomodoroSetting(): HasOne
    {
        return $this->hasOne(PomodoroSetting::class);
    }

    /** @return HasMany<AssistantAction, $this> */
    public function assistantActions(): HasMany
    {
        return $this->hasMany(AssistantAction::class);
    }

    /** @return HasMany<CalendarEvent, $this> */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }
}
