<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoutineRequest;
use App\Models\Routine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoutineController extends Controller
{
    public function store(StoreRoutineRequest $request): RedirectResponse
    {
        $routine = new Routine($this->attributes($request));
        $routine->user()->associate($request->user());
        $routine->responsibility_id = $request->validated('responsibility_id');
        $routine->save();

        return back();
    }

    /**
     * Change every occurrence of a routine. Occurrences that were moved are restored to the new
     * schedule; skipped and completed days are kept.
     */
    public function update(StoreRoutineRequest $request, string $routine): RedirectResponse
    {
        $record = $request->user()->routines()->findOrFail($routine);

        // Restore moved days first: saving the routine queues the Google sync, which must see the final state.
        $record->occurrences()->whereNotNull('starts_at')->update(['starts_at' => null, 'ends_at' => null]);

        $record->fill($this->attributes($request));
        $record->responsibility_id = $request->validated('responsibility_id');
        $record->save();

        return back();
    }

    public function destroy(Request $request, string $routine): RedirectResponse
    {
        $request->user()->routines()->findOrFail($routine)->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(StoreRoutineRequest $request): array
    {
        $validated = $request->validated();
        $days = collect($validated['days'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all();

        return [
            'title' => $validated['title'],
            'days' => $days,
            'start_time' => $validated['start_time'].':00',
            'duration_minutes' => $validated['duration_minutes'],
            'timezone' => $validated['timezone'],
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
        ];
    }
}
