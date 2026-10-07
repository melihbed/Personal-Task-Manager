<?php

use App\Models\CanvasAccount;
use App\Models\CanvasCourse;
use App\Models\User;
use App\Services\Canvas\CanvasSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Canvas test helpers
|--------------------------------------------------------------------------
|
| Shared by the Canvas test files. Loaded from tests/Pest.php.
|
*/

/** What the fake Canvas answers with. Tests change these between syncs. */
class CanvasFake
{
    /** @var list<array<string, mixed>> */
    public static array $courses = [];

    /** @var array<int, list<array<string, mixed>>> a course id => its assignments */
    public static array $assignments = [];

    /** @var array<string, int> a URL fragment => the status to answer with instead */
    public static array $failures = [];
}

/**
 * Pretends to be Canvas at njit.instructure.com.
 *
 * @param  list<array<string, mixed>>  $courses
 * @param  array<int, list<array<string, mixed>>>  $assignments
 * @param  array<string, int>  $failures
 */
function fakeCanvas(array $courses = [], array $assignments = [], array $failures = []): void
{
    CanvasFake::$courses = $courses;
    CanvasFake::$assignments = $assignments;
    CanvasFake::$failures = $failures;

    Http::fake(function (Request $request) {
        $url = $request->url();

        foreach (CanvasFake::$failures as $fragment => $status) {
            if (str_contains($url, $fragment)) {
                return Http::response(['errors' => [['message' => 'failed']]], $status);
            }
        }

        return match (true) {
            str_contains($url, '/users/self/profile') => Http::response(['id' => 7, 'name' => 'Sam Student']),
            preg_match('#/courses/(\d+)/assignments#', $url, $match) === 1 => Http::response(CanvasFake::$assignments[(int) $match[1]] ?? []),
            str_contains($url, '/api/v1/courses') => Http::response(CanvasFake::$courses),
            default => Http::response([], 404),
        };
    });
}

function canvasCourse(array $overrides = []): array
{
    return array_merge(['id' => 101, 'name' => 'Data Structures', 'course_code' => 'CS 288', 'term' => ['name' => 'Fall 2026', 'start_at' => now()->subMonth()->toIso8601String(), 'end_at' => now()->addMonths(3)->toIso8601String()]], $overrides);
}

function canvasAssignment(array $overrides = []): array
{
    return array_merge([
        'id' => 5001,
        'name' => 'Homework 1',
        'due_at' => '2026-10-12T03:59:00Z',
        'points_possible' => 20,
        'html_url' => 'https://njit.instructure.com/courses/101/assignments/5001',
        'published' => true,
        'submission_types' => ['online_upload'],
        'submission' => ['workflow_state' => 'unsubmitted', 'missing' => false, 'late' => false, 'score' => null],
    ], $overrides);
}

function submittedSubmission(array $overrides = []): array
{
    return array_merge(['workflow_state' => 'submitted', 'missing' => false, 'late' => false, 'score' => null], $overrides);
}

/** A user connected to Canvas, with one tracked course (Canvas id 101). */
function canvasUser(): User
{
    $user = User::factory()->create();
    CanvasAccount::factory()->for($user)->create();
    CanvasCourse::factory()->for($user)->create(['canvas_id' => 101, 'name' => 'Data Structures']);

    return $user;
}

function syncCanvas(User $user): void
{
    app(CanvasSync::class)->run($user->fresh());
}
