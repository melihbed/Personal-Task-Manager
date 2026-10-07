<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCalendarEventRequest extends FormRequest
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
     * A timed event is changed with starts_at and ends_at (each with a UTC offset); an all-day event with start_date and
     * end_date, the first and last day inclusive. Whether it is all day is chosen when it is made, not changed here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $offsetRule = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

        return [
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'responsibility_id' => [
                'nullable',
                'integer',
                Rule::exists('responsibilities', 'id')->where('user_id', $this->user()->id)->whereNull('archived_at'),
            ],
            'starts_at' => ['exclude_if:all_day,true', 'required', 'date', $offsetRule],
            'ends_at' => ['exclude_if:all_day,true', 'required', 'date', $offsetRule, 'after:starts_at'],
            'start_date' => ['exclude_unless:all_day,true', 'required', 'date_format:Y-m-d'],
            'end_date' => ['exclude_unless:all_day,true', 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'all_day' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'responsibility_id.exists' => 'Choose one of your active responsibilities.',
            'ends_at.after' => 'The event must end after it starts.',
            'end_date.after_or_equal' => 'The last day cannot be before the first day.',
        ];
    }
}
