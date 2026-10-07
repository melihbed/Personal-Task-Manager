<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCalendarEventRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CalendarEventController extends Controller
{
    /**
     * Change an event: its title, place, notes, responsibility and time.
     */
    public function update(UpdateCalendarEventRequest $request, string $event): RedirectResponse
    {
        $record = $request->user()->calendarEvents()->findOrFail($event);
        $data = $request->validated();

        // Whether it lasts all day is decided when it is made, so a timed event cannot be sent all-day times, or the reverse.
        if ($record->all_day !== $data['all_day']) {
            throw ValidationException::withMessages(['all_day' => 'This event was changed. Close it and open it again.']);
        }

        $record->fill([
            'title' => $data['title'],
            'location' => $this->blank($data['location'] ?? null),
            'notes' => $this->blank($data['notes'] ?? null),
        ]);
        $record->responsibility_id = $data['responsibility_id'] ?? null;

        if ($record->all_day) {
            $record->starts_on = $data['start_date'];
            $record->ends_on = $data['end_date'];
        } else {
            $record->starts_at = CarbonImmutable::parse($data['starts_at'])->utc();
            $record->ends_at = CarbonImmutable::parse($data['ends_at'])->utc();
        }

        $record->save();

        return back();
    }

    /**
     * Delete an event.
     */
    public function destroy(Request $request, string $event): RedirectResponse
    {
        $request->user()->calendarEvents()->findOrFail($event)->delete();

        return back();
    }

    private function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
