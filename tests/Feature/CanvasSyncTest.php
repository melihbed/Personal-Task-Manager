<?php

use App\Models\CalendarSession;
use App\Models\CanvasAccount;
use App\Models\CanvasAssignment;
use App\Models\Task;
use App\Services\Canvas\CanvasSync;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = canvasUser();
});

it('makes a task in the School responsibility for an assignment that is not submitted', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);

    syncCanvas($this->user);

    $task = Task::firstOrFail();
    expect($task->title)->toBe('Homework 1')
        ->and($task->user_id)->toBe($this->user->id)
        ->and($task->due_has_time)->toBeTrue()
        ->and($task->due_at->toIso8601String())->toBe('2026-10-12T03:59:00+00:00')
        ->and($task->responsibility->name)->toBe('School');

    $assignment = CanvasAssignment::firstOrFail();
    expect($assignment->task_id)->toBe($task->id)->and($assignment->kind)->toBe('assignment');
});

it('uses one School responsibility for every course', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment(), canvasAssignment(['id' => 5002, 'name' => 'Homework 2'])]]);

    syncCanvas($this->user);
    syncCanvas($this->user);

    expect($this->user->responsibilities()->where('name', 'School')->count())->toBe(1)
        ->and(Task::count())->toBe(2);
});

it('does not make a task for work that is already submitted', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment(['submission' => submittedSubmission()])]]);

    syncCanvas($this->user);

    expect(Task::count())->toBe(0)->and(CanvasAssignment::count())->toBe(1);
});

it('records quizzes and discussions as their own kind', function () {
    fakeCanvas([canvasCourse()], [101 => [
        canvasAssignment(['id' => 1, 'submission_types' => ['online_quiz']]),
        canvasAssignment(['id' => 2, 'submission_types' => ['discussion_topic']]),
    ]]);

    syncCanvas($this->user);

    expect(CanvasAssignment::orderBy('canvas_id')->pluck('kind')->all())->toBe(['quiz', 'discussion'])
        ->and(Task::count())->toBe(2);
});

it('keeps the task without a deadline when Canvas has no due date', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment(['due_at' => null])]]);

    syncCanvas($this->user);

    expect(Task::firstOrFail()->due_at)->toBeNull();
});

it('completes the task once when the assignment is submitted', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    syncCanvas($this->user);

    CanvasFake::$assignments[101] = [canvasAssignment(['submission' => submittedSubmission()])];
    syncCanvas($this->user);

    expect(Task::firstOrFail()->completed_at)->not->toBeNull();

    // The user reopens it; a later sync does not complete it again.
    Task::first()->update(['title' => 'Homework 1']);
    Task::query()->update(['completed_at' => null]);
    syncCanvas($this->user);

    expect(Task::firstOrFail()->completed_at)->toBeNull();
});

it('follows Canvas for the title and deadline but keeps the user\'s own details', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    syncCanvas($this->user);
    Task::query()->update(['notes' => 'Read chapter 3', 'priority' => 'high', 'estimate_minutes' => 90]);

    CanvasFake::$assignments[101] = [canvasAssignment(['name' => 'Homework 1 (revised)', 'due_at' => '2026-10-15T03:59:00Z'])];
    syncCanvas($this->user);

    $task = Task::firstOrFail();
    expect($task->title)->toBe('Homework 1 (revised)')
        ->and($task->due_at->toIso8601String())->toBe('2026-10-15T03:59:00+00:00')
        ->and($task->notes)->toBe('Read chapter 3')
        ->and($task->priority)->toBe('high')
        ->and($task->estimate_minutes)->toBe(90);
});

it('does not make a task again after the user deleted it', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    syncCanvas($this->user);

    Task::firstOrFail()->delete();
    syncCanvas($this->user);

    expect(Task::count())->toBe(0)->and(CanvasAssignment::firstOrFail()->task_id)->toBeNull();
});

it('removes the task of an assignment that disappeared from Canvas', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    syncCanvas($this->user);

    CanvasFake::$assignments[101] = [];
    syncCanvas($this->user);

    expect(Task::count())->toBe(0)->and(CanvasAssignment::count())->toBe(0);
});

it('removes the task of an assignment that was unpublished', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    syncCanvas($this->user);

    CanvasFake::$assignments[101] = [canvasAssignment(['published' => false])];
    syncCanvas($this->user);

    expect(Task::count())->toBe(0);
});

it('keeps the task of a vanished assignment when the user edited, planned or completed it', function (string $change) {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    syncCanvas($this->user);
    $this->travel(1)->minutes();
    $task = Task::firstOrFail();

    match ($change) {
        'edited' => $task->update(['notes' => 'My plan']),
        'completed' => $task->forceFill(['completed_at' => now()])->save(),
        'planned' => CalendarSession::withoutEvents(function () use ($task) {
            $session = new CalendarSession(['starts_at' => '2026-10-08 14:00:00', 'ends_at' => '2026-10-08 15:00:00']);
            $session->user()->associate($task->user_id);
            $session->task()->associate($task);
            $session->save();
        }),
    };

    CanvasFake::$assignments[101] = [];
    syncCanvas($this->user);

    expect(Task::count())->toBe(1)->and(CanvasAssignment::count())->toBe(0);
})->with(['edited', 'completed', 'planned']);

it('keeps a task the user edited marked as edited after Canvas moves the deadline', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    syncCanvas($this->user);
    $this->travel(1)->minutes();
    Task::query()->update(['notes' => 'My plan']);

    CanvasFake::$assignments[101] = [canvasAssignment(['due_at' => '2026-10-20T03:59:00Z'])];
    syncCanvas($this->user);

    CanvasFake::$assignments[101] = [];
    syncCanvas($this->user);

    expect(Task::count())->toBe(1);
});

it('only syncs tracked courses', function () {
    fakeCanvas([canvasCourse(), canvasCourse(['id' => 102, 'name' => 'Physics'])], [
        101 => [canvasAssignment()],
        102 => [canvasAssignment(['id' => 6001, 'name' => 'Lab 1'])],
    ]);
    $this->user->canvasCourses()->create(['canvas_id' => 102, 'name' => 'Physics', 'tracked' => false]);

    syncCanvas($this->user);

    expect(Task::pluck('title')->all())->toBe(['Homework 1']);
});

it('adds courses Canvas lists and keeps the tracking choice of known ones', function () {
    fakeCanvas([canvasCourse(['name' => 'Data Structures II']), canvasCourse(['id' => 103, 'name' => 'Ethics'])]);
    $this->user->canvasCourses()->where('canvas_id', 101)->update(['tracked' => false]);

    syncCanvas($this->user);

    $courses = $this->user->canvasCourses()->orderBy('canvas_id')->get();
    expect($courses->pluck('name')->all())->toBe(['Data Structures II', 'Ethics'])
        ->and($courses->pluck('tracked')->all())->toBe([false, true]);
});

it('only starts tracking courses from a term that is running now', function () {
    fakeCanvas([
        canvasCourse(['id' => 201, 'name' => 'Current']),
        canvasCourse(['id' => 202, 'name' => 'Past', 'term' => ['name' => 'Fall 2024', 'start_at' => '2024-09-01T00:00:00Z', 'end_at' => '2024-12-20T00:00:00Z']]),
        canvasCourse(['id' => 203, 'name' => 'No dates', 'term' => ['name' => 'Default Term', 'start_at' => null, 'end_at' => null]]),
        canvasCourse(['id' => 204, 'name' => 'No term', 'term' => null]),
    ], [201 => [canvasAssignment(['id' => 1])], 202 => [canvasAssignment(['id' => 2])]]);

    syncCanvas($this->user);

    expect($this->user->canvasCourses()->whereIn('canvas_id', [201, 202, 203, 204])->orderBy('canvas_id')->pluck('tracked')->all())->toBe([true, false, false, false])
        ->and(Task::pluck('title')->all())->toHaveCount(1);
});

it('removes the untouched tasks of a course when it stops being tracked', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment(), canvasAssignment(['id' => 5002, 'name' => 'Homework 2'])]]);
    syncCanvas($this->user);
    $this->travel(1)->minutes();
    Task::where('title', 'Homework 2')->first()->update(['notes' => 'My plan']);

    app(CanvasSync::class)->forgetCourse($this->user->canvasCourses()->firstOrFail());

    expect(Task::pluck('title')->all())->toBe(['Homework 2'])->and(CanvasAssignment::count())->toBe(0);
});

it('pages through Canvas results', function () {
    Http::fake([
        '*/users/self/profile' => Http::response(['id' => 7, 'name' => 'Sam']),
        '*/api/v1/courses?*' => Http::response([canvasCourse()]),
        '*/courses/101/assignments?page=2' => Http::response([canvasAssignment(['id' => 5002, 'name' => 'Homework 2'])]),
        '*/courses/101/assignments?*' => Http::response([canvasAssignment()], 200, ['Link' => '<https://njit.instructure.com/api/v1/courses/101/assignments?page=2>; rel="next"']),
    ]);

    syncCanvas($this->user);

    expect(Task::count())->toBe(2);
});

it('never follows a next link to another host', function () {
    Http::fake([
        '*/api/v1/courses?*' => Http::response([canvasCourse()]),
        '*/courses/101/assignments?*' => Http::response([canvasAssignment()], 200, ['Link' => '<https://evil.example.com/steal>; rel="next"']),
    ]);

    syncCanvas($this->user);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.com'));
    expect($this->user->canvasAccount->fresh()->last_error)->not->toBeNull();
});

it('asks to reconnect when Canvas rejects the token', function () {
    fakeCanvas(failures: ['/api/v1/courses' => 401]);

    syncCanvas($this->user);

    $account = $this->user->canvasAccount->fresh();
    expect($account->needs_reconnect)->toBeTrue()->and($account->last_error)->not->toBeNull();
});

it('records an error and keeps the data when Canvas fails', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    syncCanvas($this->user);

    CanvasFake::$failures = ['/courses/101/assignments' => 500];
    syncCanvas($this->user);

    expect(Task::count())->toBe(1)
        ->and($this->user->canvasAccount->fresh()->last_error)->not->toBeNull()
        ->and($this->user->canvasAccount->fresh()->needs_reconnect)->toBeFalse();
});

it('records the time of a good sync and clears the earlier error', function () {
    $this->user->canvasAccount->update(['last_error' => 'old']);
    fakeCanvas([canvasCourse()]);

    syncCanvas($this->user);

    $account = $this->user->canvasAccount->fresh();
    expect($account->last_synced_at)->not->toBeNull()->and($account->last_error)->toBeNull();
});

it('does not call Canvas for a user who needs to reconnect', function () {
    $this->user->canvasAccount->update(['needs_reconnect' => true]);
    fakeCanvas([canvasCourse()]);

    syncCanvas($this->user);

    Http::assertNothingSent();
});

it('does not push the deadline of a Canvas task to Google', function () {
    $user = googleUser();
    CanvasAccount::factory()->for($user)->create();
    $user->canvasCourses()->create(['canvas_id' => 101, 'name' => 'Data Structures']);
    fakeGoogle();
    Http::fake([
        ...[],
    ]);
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);

    syncCanvas($user);

    expect(googleWrites())->toHaveCount(0);
});
