<?php

use App\Models\CanvasAssignment;
use App\Models\CanvasCourse;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the School page as not connected', function () {
    $this->actingAs(User::factory()->create())->get('/school')
        ->assertInertia(fn (Assert $page) => $page->component('school/index')->where('state', 'not_connected')->where('assignments', []));
});

it('lists open work, soonest first, with undated work last', function () {
    $user = canvasUser();
    $course = $user->canvasCourses()->firstOrFail();
    CanvasAssignment::factory()->for($course, 'course')->create(['name' => 'Later', 'due_at' => now()->addDays(9)]);
    CanvasAssignment::factory()->for($course, 'course')->create(['name' => 'Undated', 'due_at' => null]);
    CanvasAssignment::factory()->for($course, 'course')->create(['name' => 'Soon', 'due_at' => now()->addDay()]);
    CanvasAssignment::factory()->for($course, 'course')->create(['name' => 'Missed', 'due_at' => now()->subDays(20), 'missing' => true]);

    $this->actingAs($user)->get('/school')
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'connected')
            ->where('assignments.*.name', ['Missed', 'Soon', 'Later', 'Undated'])
            ->where('assignments.0.missing', true)
            ->where('assignments.0.course_name', 'Data Structures'));
});

it('keeps recently submitted work but not old submitted work', function () {
    $user = canvasUser();
    $course = $user->canvasCourses()->firstOrFail();
    CanvasAssignment::factory()->for($course, 'course')->create(['name' => 'Recent', 'due_at' => now()->subDays(3), 'submitted' => true]);
    CanvasAssignment::factory()->for($course, 'course')->create(['name' => 'Old', 'due_at' => now()->subDays(40), 'submitted' => true]);

    $this->actingAs($user)->get('/school')
        ->assertInertia(fn (Assert $page) => $page->where('assignments.*.name', ['Recent']));
});

it('leaves out courses that are not tracked and other people\'s work', function () {
    $user = canvasUser();
    $untracked = CanvasCourse::factory()->for($user)->create(['tracked' => false]);
    CanvasAssignment::factory()->for($untracked, 'course')->create(['name' => 'Hidden course']);
    $other = canvasUser();
    CanvasAssignment::factory()->for($other->canvasCourses()->firstOrFail(), 'course')->create(['name' => 'Not mine']);

    $this->actingAs($user)->get('/school')
        ->assertInertia(fn (Assert $page) => $page->where('assignments', [])->where('courses.0.name', 'Data Structures'));
});

it('tells the dashboard which course and link a Canvas task came from', function () {
    fakeCanvas([canvasCourse()], [101 => [canvasAssignment()]]);
    $user = canvasUser();
    syncCanvas($user);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.canvas_assignment.course.name', 'Data Structures')
            ->where('tasks.0.canvas_assignment.html_url', 'https://njit.instructure.com/courses/101/assignments/5001'));
});

it('counts work as done when its task was completed here', function () {
    $user = canvasUser();
    $course = $user->canvasCourses()->firstOrFail();
    $task = $user->tasks()->create(['title' => 'Quiz prep', 'priority' => 'normal']);
    $task->forceFill(['completed_at' => now()])->save();
    CanvasAssignment::factory()->for($course, 'course')->create(['name' => 'Ticked off', 'due_at' => now()->addHours(5), 'task_id' => $task->id]);

    $this->actingAs($user)->get('/school')
        ->assertInertia(fn (Assert $page) => $page->where('assignments.0.done', true)->where('assignments.0.submitted', false));
});
