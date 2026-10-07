<?php

namespace App\Http\Controllers;

use App\Models\CanvasAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolController extends Controller
{
    /**
     * Canvas work. Work still to do is always listed; finished work (submitted in Canvas, or its task completed
     * here) only for the last two weeks, so the page stays about what needs attention.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $account = $user->canvasAccount;
        $recent = now()->subWeeks(2);

        $assignments = $account === null ? collect() : $user->canvasAssignments()
            ->whereHas('course', fn ($course) => $course->where('tracked', true))
            ->with(['course:id,name,course_code', 'task:id,completed_at'])
            ->orderByRaw('due_at is null')->orderBy('due_at')
            ->get()
            ->map(fn ($assignment) => [$assignment, $assignment->submitted || $assignment->task?->completed_at !== null])
            ->filter(fn (array $pair) => ! $pair[1] || ($pair[0]->due_at ?? $pair[0]->updated_at) >= $recent);

        return Inertia::render('school/index', [
            'state' => CanvasAccount::stateOf($account),
            'lastSyncedAt' => $account?->last_synced_at?->toIso8601String(),
            'courses' => $account === null ? [] : $user->canvasCourses()->where('tracked', true)->orderBy('name')->get(['id', 'name', 'course_code']),
            'assignments' => $assignments->map(fn (array $pair) => [
                'id' => $pair[0]->id,
                'name' => $pair[0]->name,
                'kind' => $pair[0]->kind,
                'course_id' => $pair[0]->canvas_course_id,
                'course_name' => $pair[0]->course->name,
                'due_at' => $pair[0]->due_at?->utc()->toIso8601String(),
                'points_possible' => $pair[0]->points_possible,
                'score' => $pair[0]->score,
                'url' => $pair[0]->html_url,
                'submitted' => $pair[0]->submitted,
                'missing' => $pair[0]->missing,
                'late' => $pair[0]->late,
                'done' => $pair[1],
                'task_id' => $pair[0]->task_id,
            ])->values()->all(),
        ]);
    }
}
