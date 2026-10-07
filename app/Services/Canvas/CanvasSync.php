<?php

namespace App\Services\Canvas;

use App\Models\CanvasAccount;
use App\Models\CanvasAssignment;
use App\Models\CanvasCourse;
use App\Models\Responsibility;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies the user's Canvas courses and assignments into the app and keeps one planner task per assignment.
 * It is safe to run repeatedly, and it only reads from Canvas.
 *
 * Task rules: an assignment gets a task unless it is already submitted; a submission completes the task
 * once; Canvas decides the task's title and deadline; a task the user deleted is not made again; and an
 * assignment that disappears from Canvas takes its task with it, unless the user edited, planned or
 * completed that task.
 */
class CanvasSync
{
    public function run(User $user): void
    {
        $account = $user->canvasAccount;

        if ($account === null || $account->needs_reconnect) {
            return;
        }

        $client = CanvasClient::for($account);

        // A sync from a web request can outlast PHP's default 30 seconds on a slow Canvas.
        set_time_limit(120);

        try {
            $courseIds = $this->syncCourses($user, $client);

            foreach ($user->canvasCourses()->where('tracked', true)->whereIn('canvas_id', $courseIds)->get() as $course) {
                $this->syncAssignments($user, $account, $course, $client->assignments($course->canvas_id));
            }
        } catch (CanvasReconnectRequired $exception) {
            $account->update(['needs_reconnect' => true, 'last_error' => 'Canvas rejected the access token. Paste a new one to reconnect.']);

            return;
        } catch (CanvasApiException $exception) {
            $account->update(['last_error' => $exception->getMessage()]);

            return;
        }

        $account->update(['last_synced_at' => now(), 'last_error' => null]);
    }

    /**
     * @return list<int> the Canvas ids of the courses Canvas lists as active
     */
    private function syncCourses(User $user, CanvasClient $client): array
    {
        $ids = [];

        foreach ($client->courses() as $payload) {
            $ids[] = (int) $payload['id'];

            $course = $user->canvasCourses()->firstOrNew(['canvas_id' => $payload['id']]);

            // Canvas keeps old courses listed as active, so only courses in a term that is running now start tracked.
            if (! $course->exists) {
                $course->tracked = $this->inCurrentTerm($payload);
            }

            $course->fill([
                'name' => Str::limit($payload['name'], 250, ''),
                'course_code' => isset($payload['course_code']) ? Str::limit($payload['course_code'], 250, '') : null,
                'term' => isset($payload['term']['name']) ? Str::limit($payload['term']['name'], 250, '') : null,
            ])->save();
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function inCurrentTerm(array $payload): bool
    {
        $term = $payload['term'] ?? [];
        $starts = isset($term['start_at']) ? CarbonImmutable::parse($term['start_at']) : null;
        $ends = isset($term['end_at']) ? CarbonImmutable::parse($term['end_at']) : null;

        return $starts !== null && $ends !== null && $starts->isPast() && $ends->isFuture();
    }

    /**
     * Stops tracking's effect on tasks: removes a course's tasks the user has not touched, and forgets its
     * assignments. Tasks the user edited, planned or completed stay as ordinary tasks.
     */
    public function forgetCourse(CanvasCourse $course): void
    {
        foreach ($course->assignments()->with('task')->get() as $assignment) {
            DB::transaction(function () use ($assignment) {
                if ($assignment->task !== null && ! $this->userTouched($assignment->task, $assignment)) {
                    $assignment->task->delete();
                }

                $assignment->delete();
            });
        }
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function syncAssignments(User $user, CanvasAccount $account, CanvasCourse $course, array $payloads): void
    {
        $seen = [];

        foreach ($payloads as $payload) {
            if (($payload['published'] ?? true) === false) {
                continue;
            }

            $seen[] = (int) $payload['id'];

            DB::transaction(fn () => $this->syncAssignment($user, $account, $course, $payload));
        }

        $gone = $course->assignments()->whereNotIn('canvas_id', $seen)->get();

        foreach ($gone as $assignment) {
            DB::transaction(function () use ($assignment) {
                $task = $assignment->task;

                if ($task !== null && ! $this->userTouched($task, $assignment)) {
                    $task->delete();
                }

                $assignment->delete();
            });
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncAssignment(User $user, CanvasAccount $account, CanvasCourse $course, array $payload): void
    {
        $assignment = $course->assignments()->firstOrNew(['canvas_id' => $payload['id']]);
        $assignment->user_id = $user->id;
        $wasSubmitted = $assignment->submitted;

        $assignment->fill($this->attributes($payload))->save();

        if ($assignment->task_id !== null) {
            $this->updateTask($assignment, $wasSubmitted);

            return;
        }

        if ($assignment->task_created || $assignment->submitted) {
            return;
        }

        $this->createTask($user, $account, $assignment);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attributes(array $payload): array
    {
        $submission = $payload['submission'] ?? [];
        $types = $payload['submission_types'] ?? [];

        return [
            'name' => Str::limit((string) $payload['name'], 250, ''),
            'kind' => match (true) {
                in_array('online_quiz', $types, true) => 'quiz',
                in_array('discussion_topic', $types, true) => 'discussion',
                default => 'assignment',
            },
            'due_at' => isset($payload['due_at']) ? CarbonImmutable::parse($payload['due_at'])->utc() : null,
            'points_possible' => $payload['points_possible'] ?? null,
            'html_url' => $payload['html_url'] ?? null,
            'submitted' => in_array($submission['workflow_state'] ?? 'unsubmitted', ['submitted', 'graded', 'pending_review'], true)
                || ($submission['excused'] ?? false),
            'missing' => (bool) ($submission['missing'] ?? false),
            'late' => (bool) ($submission['late'] ?? false),
            'score' => $submission['score'] ?? null,
        ];
    }

    private function createTask(User $user, CanvasAccount $account, CanvasAssignment $assignment): void
    {
        $task = new Task(['title' => $assignment->name, 'priority' => 'normal', 'due_at' => $assignment->due_at, 'due_has_time' => true]);
        $task->user()->associate($user);
        $task->responsibility_id = $this->school($user, $account)->id;
        $task->save();

        $assignment->update(['task_id' => $task->id, 'task_created' => true, 'task_synced_at' => $task->updated_at]);
    }

    private function updateTask(CanvasAssignment $assignment, bool $wasSubmitted): void
    {
        $task = $assignment->task;
        $untouched = ! $this->userEdited($task, $assignment);

        $task->title = $assignment->name;
        $task->due_at = $assignment->due_at;
        $task->due_has_time = true;

        if ($assignment->submitted && ! $wasSubmitted && $task->completed_at === null) {
            $task->completed_at = now();
        }

        if ($task->isDirty()) {
            $task->save();

            // A task the user changed stays marked as changed, so it is never removed behind their back.
            if ($untouched) {
                $assignment->update(['task_synced_at' => $task->updated_at]);
            }
        }
    }

    private function userEdited(Task $task, CanvasAssignment $assignment): bool
    {
        return $assignment->task_synced_at === null || $task->updated_at->timestamp !== $assignment->task_synced_at->timestamp;
    }

    private function userTouched(Task $task, CanvasAssignment $assignment): bool
    {
        return $task->completed_at !== null || $this->userEdited($task, $assignment) || $task->calendarSessions()->exists();
    }

    /** The one School responsibility every Canvas task goes to; it is made again if the user deleted it. */
    private function school(User $user, CanvasAccount $account): Responsibility
    {
        $responsibility = $account->responsibility_id !== null ? $user->responsibilities()->find($account->responsibility_id) : null;

        if ($responsibility === null) {
            $responsibility = $user->responsibilities()->create(['name' => 'School', 'description' => 'Assignments from Canvas.', 'color' => '#2563eb']);
            $account->update(['responsibility_id' => $responsibility->id]);
        }

        return $responsibility;
    }
}
