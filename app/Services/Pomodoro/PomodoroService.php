<?php

namespace App\Services\Pomodoro;

use App\Models\PomodoroSession;
use App\Models\PomodoroSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Pomodoro timer. A user has at most one running or paused session. Remaining time is worked out from the
 * stored start time, so the timer keeps going across page loads, and the server decides when a round is really
 * finished: a round only counts once its full time has elapsed.
 */
class PomodoroService
{
    /** How much earlier than the planned time a round may be completed, to forgive clock drift between browser and server. */
    private const TOLERANCE_SECONDS = 3;

    /** A session left running this long is a forgotten one and is abandoned. */
    private const STALE_HOURS = 24;

    /** Focus rounds further back than this no longer count towards the current set of rounds. */
    private const SET_WINDOW_HOURS = 12;

    public function settings(User $user): PomodoroSetting
    {
        return $user->pomodoroSetting ?? new PomodoroSetting(PomodoroSetting::DEFAULTS);
    }

    /**
     * Everything the timer UI needs to draw itself.
     *
     * @return array<string, mixed>
     */
    public function state(User $user): array
    {
        $now = CarbonImmutable::now();
        $settings = $this->settings($user);
        $active = $this->active($user);
        $done = $this->roundsInCurrentSet($user);
        $last = $user->pomodoroSessions()->where('status', 'completed')->where('ended_at', '>=', $now->subHour())->latest('ended_at')->first();

        return [
            'settings' => $settings->only(array_keys(PomodoroSetting::DEFAULTS)),
            'active' => $active === null ? null : [
                'id' => $active->id,
                'kind' => $active->kind,
                'status' => $active->status,
                'task_id' => $active->task_id,
                'task_title' => $active->task?->title,
                'planned_seconds' => $active->planned_seconds,
                'remaining_seconds' => max(0, $active->planned_seconds - $this->elapsed($active, $now)),
            ],
            'cycle' => [
                'done' => min($done, $settings->rounds_before_long),
                'of' => $settings->rounds_before_long,
                'next_break' => $done >= $settings->rounds_before_long ? PomodoroSession::LONG_BREAK : PomodoroSession::SHORT_BREAK,
            ],
            // The round or break that just ended, so the page can offer what comes next even after a reload.
            'last_completed' => $last === null ? null : ['kind' => $last->kind, 'ended_at' => $last->ended_at->utc()->toIso8601String()],
            'server_now' => $now->toIso8601String(),
        ];
    }

    /**
     * @throws PomodoroException
     */
    public function start(User $user, string $kind, ?int $taskId): PomodoroSession
    {
        $settings = $this->settings($user);
        $minutes = match ($kind) {
            PomodoroSession::FOCUS => $settings->focus_minutes,
            PomodoroSession::SHORT_BREAK => $settings->short_break_minutes,
            PomodoroSession::LONG_BREAK => $settings->long_break_minutes,
            default => throw new PomodoroException('Choose focus, short break or long break.'),
        };

        $task = null;

        if ($taskId !== null && $kind === PomodoroSession::FOCUS) {
            $task = $user->tasks()->whereNull('completed_at')->find($taskId) ?? throw new PomodoroException('Choose one of your open tasks.');
        }

        return DB::transaction(function () use ($user, $kind, $minutes, $task) {
            // Serialize this user's timer actions, so two quick taps cannot start two timers.
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            if ($this->active($user) !== null) {
                throw new PomodoroException('A timer is already running. Finish or stop it first.');
            }

            $session = new PomodoroSession(['kind' => $kind, 'planned_seconds' => $minutes * 60, 'started_at' => now(), 'status' => 'running']);
            $session->user()->associate($user);
            $session->task()->associate($task);
            $session->save();

            return $session;
        });
    }

    /**
     * @throws PomodoroException
     */
    public function pause(PomodoroSession $session): void
    {
        $this->requireStatus($session, 'running', 'This timer is not running.');

        $session->update(['status' => 'paused', 'paused_at' => now()]);
    }

    /**
     * @throws PomodoroException
     */
    public function resume(PomodoroSession $session): void
    {
        $this->requireStatus($session, 'paused', 'This timer is not paused.');

        $session->update(['status' => 'running', 'paused_seconds' => $session->paused_seconds + (int) $session->paused_at->diffInSeconds(now()), 'paused_at' => null]);
    }

    /**
     * Counts the round as done, if its full time has passed.
     *
     * @throws PomodoroException
     */
    public function complete(PomodoroSession $session): void
    {
        if (! $session->isActive()) {
            throw new PomodoroException('This timer has already ended.');
        }

        if ($this->elapsed($session, CarbonImmutable::now()) + self::TOLERANCE_SECONDS < $session->planned_seconds) {
            throw new PomodoroException('This round is not finished yet.');
        }

        $session->update(['status' => 'completed', 'ended_at' => $this->endOf($session), 'paused_at' => null]);
    }

    /**
     * Stops a round early. It is not counted.
     *
     * @throws PomodoroException
     */
    public function abandon(PomodoroSession $session): void
    {
        if (! $session->isActive()) {
            throw new PomodoroException('This timer has already ended.');
        }

        $session->update(['status' => 'abandoned', 'ended_at' => now(), 'paused_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveSettings(User $user, array $values): PomodoroSetting
    {
        return $user->pomodoroSetting()->updateOrCreate([], $values);
    }

    /**
     * Today's rounds and minutes, the last seven days, the streak, and the latest rounds. Days are the user's own.
     *
     * @return array<string, mixed>
     */
    public function stats(User $user, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $rounds = $user->pomodoroSessions()->where('kind', PomodoroSession::FOCUS)->where('status', 'completed')
            ->where('ended_at', '>=', $today->subDays(400))->orderBy('ended_at')->with('task:id,title')->get(['id', 'task_id', 'planned_seconds', 'ended_at']);

        $byDay = $rounds->groupBy(fn (PomodoroSession $round) => $round->ended_at->setTimezone($timezone)->toDateString());
        $day = fn (string $date) => ['date' => $date, 'rounds' => $byDay->get($date)?->count() ?? 0, 'minutes' => (int) round(($byDay->get($date)?->sum('planned_seconds') ?? 0) / 60)];

        $streak = 0;

        // Today may not have a round yet; the streak then runs up to yesterday.
        for ($date = $byDay->has($today->toDateString()) ? $today : $today->subDay(); $byDay->has($date->toDateString()); $date = $date->subDay()) {
            $streak++;
        }

        return [
            'today' => $day($today->toDateString()),
            'week' => collect(range(6, 0))->map(fn (int $ago) => $day($today->subDays($ago)->toDateString()))->all(),
            'streak' => $streak,
            'total_rounds' => $user->pomodoroSessions()->where('kind', PomodoroSession::FOCUS)->where('status', 'completed')->count(),
            'recent' => $rounds->reverse()->take(8)->map(fn (PomodoroSession $round) => [
                'id' => $round->id,
                'task_title' => $round->task?->title,
                'minutes' => (int) round($round->planned_seconds / 60),
                'ended_at' => $round->ended_at->utc()->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function active(User $user): ?PomodoroSession
    {
        $session = $user->pomodoroSessions()->whereIn('status', ['running', 'paused'])->with('task:id,title')->latest('id')->first();

        if ($session !== null && $session->started_at->lt(now()->subHours(self::STALE_HOURS))) {
            $session->update(['status' => 'abandoned', 'ended_at' => now(), 'paused_at' => null]);

            return null;
        }

        return $session;
    }

    /** Seconds of the session that have actually been spent: wall time since the start, less any time paused. */
    private function elapsed(PomodoroSession $session, CarbonImmutable $now): int
    {
        $until = $session->paused_at !== null ? CarbonImmutable::instance($session->paused_at) : $now;

        return max(0, (int) $session->started_at->diffInSeconds($until) - $session->paused_seconds);
    }

    /** When the round really finished, which can be earlier than now if the tab was closed. */
    private function endOf(PomodoroSession $session): CarbonImmutable
    {
        $end = CarbonImmutable::instance($session->started_at)->addSeconds($session->planned_seconds + $session->paused_seconds);

        return $end->isFuture() ? CarbonImmutable::now() : $end;
    }

    /** Focus rounds finished since the last long break (and within the last half day). */
    private function roundsInCurrentSet(User $user): int
    {
        $since = now()->subHours(self::SET_WINDOW_HOURS);
        $lastLongBreak = $user->pomodoroSessions()->where('kind', PomodoroSession::LONG_BREAK)->where('status', 'completed')->where('ended_at', '>=', $since)->max('ended_at');

        return $user->pomodoroSessions()->where('kind', PomodoroSession::FOCUS)->where('status', 'completed')
            ->where('ended_at', '>=', $lastLongBreak ?? $since)->count();
    }

    /**
     * @throws PomodoroException
     */
    private function requireStatus(PomodoroSession $session, string $status, string $message): void
    {
        if ($session->status !== $status) {
            throw new PomodoroException($message);
        }
    }
}
