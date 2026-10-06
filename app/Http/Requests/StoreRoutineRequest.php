<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoutineRequest extends FormRequest
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
     * days are ISO weekdays (1 = Monday ... 7 = Sunday). start_time is a wall-clock time in
     * `timezone`, so the routine stays at the same local time across daylight saving changes.
     * A null ends_on means the routine continues until it is deleted.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'responsibility_id' => [
                'nullable',
                'integer',
                Rule::exists('responsibilities', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('archived_at'),
            ],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'timezone' => ['required', 'timezone'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
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
            'ends_on.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }
}
