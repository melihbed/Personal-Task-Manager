<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
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
     * A null responsibility_id places the task in the Inbox. Otherwise the
     * responsibility must belong to the signed-in user and not be archived.
     * due_at needs an explicit UTC offset because a bare wall-clock time is ambiguous.
     * due_has_time is false for a date-only deadline (stored as noon UTC on that date).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $offsetRule = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

        return [
            'title' => ['required', 'string', 'max:255'],
            'responsibility_id' => [
                'nullable',
                'integer',
                Rule::exists('responsibilities', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('archived_at'),
            ],
            'due_at' => ['nullable', 'date', $offsetRule],
            'due_has_time' => ['nullable', 'boolean'],
            'estimate_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'responsibility_id.exists' => 'Choose one of your active responsibilities.',
        ];
    }
}
