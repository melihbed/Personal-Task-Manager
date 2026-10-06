<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoutineOccurrenceController extends Controller
{
    /**
     * Skip, complete or move a single occurrence of a routine. Only exceptions are stored; a row
     * that returns to the routine's defaults is removed.
     */
    public function update(Request $request, string $routine, string $date): RedirectResponse
    {
        $record = $request->user()->routines()->findOrFail($routine);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $record->occursOn($date), 404);

        $offsetRule = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

        $validated = $request->validate([
            'skipped' => ['sometimes', 'boolean'],
            'completed' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date', $offsetRule, 'required_with:ends_at'],
            'ends_at' => ['nullable', 'date', $offsetRule, 'required_with:starts_at', 'after:starts_at'],
        ]);

        $occurrence = $record->occurrences()->firstOrNew(['occurs_on' => $date]);

        if (array_key_exists('skipped', $validated)) {
            $occurrence->skipped = $validated['skipped'];
        }

        if (array_key_exists('completed', $validated)) {
            $occurrence->completed_at = $validated['completed'] ? ($occurrence->completed_at ?? now()) : null;
        }

        if ($request->has('starts_at')) {
            $start = isset($validated['starts_at']) ? CarbonImmutable::parse($validated['starts_at'])->utc() : null;
            $end = isset($validated['ends_at']) ? CarbonImmutable::parse($validated['ends_at'])->utc() : null;

            if ($start !== null && $start->diffInSeconds($end) > 86400) {
                throw ValidationException::withMessages(['ends_at' => 'Keep a routine within 24 hours.']);
            }

            $occurrence->starts_at = $start;
            $occurrence->ends_at = $end;
        }

        if (! $occurrence->skipped && $occurrence->completed_at === null && $occurrence->starts_at === null) {
            if ($occurrence->exists) {
                $occurrence->delete();
            }
        } else {
            $occurrence->save();
        }

        return back();
    }
}
