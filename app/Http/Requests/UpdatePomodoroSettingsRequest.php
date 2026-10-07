<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePomodoroSettingsRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'focus_minutes' => ['required', 'integer', 'min:5', 'max:180'],
            'short_break_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'long_break_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'rounds_before_long' => ['required', 'integer', 'min:2', 'max:8'],
            'sound_enabled' => ['required', 'boolean'],
        ];
    }
}
