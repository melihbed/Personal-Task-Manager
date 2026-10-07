<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Made from a click-and-drag on the calendar: starts_at and ends_at (each with a UTC offset) are the dragged range.
     * A task can reserve that time as a work session and take its end as the deadline; an event is added to Google
     * Calendar; a routine repeats on the chosen ISO weekdays (1 = Monday ... 7 = Sunday) at the range's time of day.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $offsetRule = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';
        $type = $this->input('type');

        return [
            'type' => ['required', Rule::in(['task', 'event', 'routine'])],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', $offsetRule],
            'ends_at' => ['required', 'date', $offsetRule, 'after:starts_at'],
            'timezone' => ['required', 'timezone'],
            'responsibility_id' => [
                'nullable',
                'integer',
                Rule::exists('responsibilities', 'id')->where('user_id', $this->user()->id)->whereNull('archived_at'),
            ],
            'reserve' => ['nullable', Rule::requiredIf($type === 'task'), 'boolean'],
            'deadline_at_end' => ['nullable', 'boolean'],
            'allow_overlap' => ['nullable', 'boolean'],
            'calendar_id' => ['nullable', 'string', 'max:255'],
            'days' => [Rule::requiredIf($type === 'routine'), 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'responsibility_id.exists' => 'Choose one of your active responsibilities.',
            'days.required' => 'Choose at least one day.',
            'days.min' => 'Choose at least one day.',
            'ends_at.after' => 'The end must be after the start.',
        ];
    }
}
