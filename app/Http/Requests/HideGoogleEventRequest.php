<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HideGoogleEventRequest extends FormRequest
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
     * Hiding only changes what the planner shows; nothing changes in Google. Hiding a series needs its id.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'calendar_id' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:1024'],
            'scope' => ['required', Rule::in(['event', 'series'])],
            'recurring_event_id' => ['required_if:scope,series', 'nullable', 'string', 'max:1024'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
