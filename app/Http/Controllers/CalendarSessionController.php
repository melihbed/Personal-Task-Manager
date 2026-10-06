<?php

namespace App\Http\Controllers;

use App\Models\CalendarSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CalendarSessionController extends Controller
{
    public function store(Request $request, string $task): RedirectResponse
    {
        $record = $request->user()->tasks()->findOrFail($task);
        // TODO: Reject with 403 Forbidden if the task has a responsibility and either:
        //- That responsibility belongs to another user.
        //- That responsibility is archived.
        // A task without a responsibility can continue.
        abort_if($record->responsibility &&
            ($record->responsibility->user_id !== $request->user()->id
                || $record->responsibility->archived_at !== null), 403);
        // TODO: If the task is already completed, stop execution and send an error back to the form.
        if ($record->completed_at !== null) {
            throw ValidationException::withMessages(['starts_at' => 'Reopen this task before scheduling it.']);
        }
        // Explicit offsets are required: local wall-clock values alone are ambiguous.
        $offsetRule = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';
        // TODO: Validate the submitted fields
        $validated = $request->validate([
            'starts_at' => ['required', 'date', $offsetRule],
            'ends_at' => ['required', 'date', $offsetRule, 'after:starts_at'],
            'allow_overlap' => ['required', 'boolean'],
        ]);
        // TODO: Convert the values into UTC date objects
        $start = CarbonImmutable::parse($validated['starts_at'])->utc();
        $end = CarbonImmutable::parse($validated['ends_at'])->utc();
        if ($start->diffInSeconds($end) > 86400) {
            throw ValidationException::withMessages(['ends_at' => 'Keep a work session within 24 hours.']);
        }

        DB::transaction(function () use ($request, $record, $validated, $start, $end) {
            // Serialize this user's schedule writes so concurrent saves cannot bypass the warning.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $conflict = CalendarSession::where('user_id', $request->user()->id)
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
            if ($conflict && !$validated['allow_overlap']) {
                throw ValidationException::withMessages([
                    'allow_overlap' => 'This overlaps an existing work session. Check “Save despite overlap” to continue.',
                ]);
            }
            $session = new CalendarSession();
            $session->user()->associate($request->user());
            $session->task()->associate($record);
            $session->starts_at = $start;
            $session->ends_at = $end;
            $session->save();
        });
        return back();
    }

    public function destroy(Request $request, string $session): RedirectResponse
    {
        CalendarSession::where('user_id', $request->user()->id)->findOrFail($session)->delete();
        return back();
    }

    public function update(Request $request, string $session): RedirectResponse
    {
        $record = CalendarSession::where('user_id', $request->user()->id)->findOrFail($session);

        // Offset rule
        $offsetRule = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

        // TODO: Validate the new times
        $validated = $request->validate([
            'starts_at' => ['required', 'date', $offsetRule],
            'ends_at' => ['required', 'date', $offsetRule, 'after:starts_at'],
            'allow_overlap' => ['required', 'boolean'],
        ]);

        // TODO: Convert the times to UTC
        $start = CarbonImmutable::parse($validated['starts_at'])->utc();
        $end = CarbonImmutable::parse($validated['ends_at'])->utc();
        if ($start->diffInSeconds($end) > 86400) {
            throw ValidationException::withMessages(['ends_at' => 'Keep a work session within 24 hours.']);
        }
        DB::transaction(function () use ($request, $record, $validated, $start, $end) {
            DB::table('users')
                ->where('id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            $conflict = CalendarSession::where('user_id', $request->user()->id)
                ->where('id', '!=', $record->id) // Ignore this session.
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->exists();

            if ($conflict && !$validated['allow_overlap']) {
                throw ValidationException::withMessages([
                    'allow_overlap' => 'This overlaps an existing work session. Check “Save despite overlap” to continue.',
                ]);
            }

            // Change the existing session.
            $record->starts_at = $start;
            $record->ends_at = $end;
            $record->save();
        });

        return back();

    }
}
