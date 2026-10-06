<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGoogleCalendarRequest extends FormRequest
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
     * The calendar ids are checked against the user's real calendar list in the controller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'calendar_id' => ['required', 'string', 'max:255'],
            'import_calendar_ids' => ['present', 'array', 'max:20'],
            'import_calendar_ids.*' => ['string', 'max:255', 'distinct'],
            'push_sessions' => ['required', 'boolean'],
            'push_routines' => ['required', 'boolean'],
            'push_deadlines' => ['required', 'boolean'],
        ];
    }
}
