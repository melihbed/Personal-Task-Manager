<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Changing a task's details. The same rules as creating one, plus notes. The whole form is sent, so a missing
 * deadline, duration or responsibility means "none".
 */
class UpdateTaskRequest extends StoreTaskRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
