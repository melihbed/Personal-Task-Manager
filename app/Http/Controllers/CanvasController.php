<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConnectCanvasRequest;
use App\Http\Requests\UpdateCanvasCoursesRequest;
use App\Models\CanvasAccount;
use App\Services\Canvas\CanvasApiException;
use App\Services\Canvas\CanvasClient;
use App\Services\Canvas\CanvasReconnectRequired;
use App\Services\Canvas\CanvasSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CanvasController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $account = $user->canvasAccount;

        return Inertia::render('settings/canvas', [
            'state' => CanvasAccount::stateOf($account),
            'account' => $account ? [
                'base_url' => $account->base_url,
                'name' => $account->canvas_user_name,
                'needs_reconnect' => $account->needs_reconnect,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                'last_error' => $account->last_error,
            ] : null,
            'courses' => $account ? $user->canvasCourses()->withCount(['assignments as assignments_count'])->orderBy('name')
                ->get(['id', 'canvas_id', 'name', 'course_code', 'term', 'tracked'])
                ->map(fn ($course) => [...$course->only(['id', 'name', 'course_code', 'term', 'tracked']), 'assignments_count' => $course->assignments_count])
                ->values()->all() : [],
            'tasksCreated' => $account ? $user->canvasAssignments()->whereNotNull('task_id')->count() : 0,
        ]);
    }

    /**
     * Connects with a personal access token. The token is tested against Canvas first, and nothing is saved
     * if Canvas does not accept it. Connecting also runs the first sync.
     */
    public function store(ConnectCanvasRequest $request, CanvasSync $sync): RedirectResponse
    {
        $validated = $request->validated();
        $baseUrl = CanvasClient::normalizeBaseUrl($validated['address']);

        if ($baseUrl === null) {
            throw ValidationException::withMessages(['address' => 'Enter your school\'s Canvas address, like njit.instructure.com.']);
        }

        $token = trim($validated['token']);

        try {
            $profile = (new CanvasClient($baseUrl, $token))->profile();
        } catch (CanvasReconnectRequired) {
            throw ValidationException::withMessages(['token' => 'Canvas did not accept this token. Check that it was copied in full and has not expired.']);
        } catch (CanvasApiException $exception) {
            throw ValidationException::withMessages(['address' => 'Could not use this Canvas address: '.$exception->getMessage()]);
        }

        $user = $request->user();
        $user->canvasAccount()->updateOrCreate([], [
            'base_url' => $baseUrl,
            'access_token' => $token,
            'canvas_user_name' => $profile['name'],
            'needs_reconnect' => false,
            'last_error' => null,
        ]);

        $sync->run($user->refresh());

        return redirect()->route('canvas.show')->with('status', 'Canvas connected.');
    }

    /** Chooses which courses are tracked, then syncs so tasks follow the choice. */
    public function update(UpdateCanvasCoursesRequest $request, CanvasSync $sync): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->canvasAccount === null, 404);

        $tracked = $request->validated('tracked_course_ids');

        foreach ($user->canvasCourses()->get() as $course) {
            $course->update(['tracked' => in_array($course->id, $tracked, true)]);

            if (! $course->tracked) {
                $sync->forgetCourse($course);
            }
        }

        if ($user->canvasAccount->needs_reconnect === false) {
            $sync->run($user);
        }

        return back()->with('status', 'Courses saved.');
    }

    /** Disconnects: forgets the token. Tasks already made stay, and are not made again if the user connects later. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->canvasAccount?->delete();

        return redirect()->route('canvas.show')->with('status', 'Canvas disconnected.');
    }
}
