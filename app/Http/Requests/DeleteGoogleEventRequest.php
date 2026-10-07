<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteGoogleEventRequest extends FormRequest
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
     * scope is "event" for just that event, or "series" for the whole repeating series it belongs to. The
     * calendar is checked against the user's chosen calendars in the controller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'calendar_id' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:1024'],
            'scope' => ['required', Rule::in(['event', 'series'])],
        ];
    }
}
