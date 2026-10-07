<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportGoogleEventRequest extends FormRequest
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
     * The event itself is read from Google, never taken from the request, so only its address is sent.
     * The calendar is checked against the user's chosen calendars in the controller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'calendar_id' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:1024'],
            'type' => ['required', Rule::in(['event', 'task', 'session', 'routine'])],
            'responsibility_id' => [
                'nullable',
                'integer',
                Rule::exists('responsibilities', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('archived_at'),
            ],
            'timezone' => ['required', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['responsibility_id.exists' => 'Choose one of your active responsibilities.'];
    }
}
