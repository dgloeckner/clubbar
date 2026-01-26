<?php

namespace App\Http\Modules\Terminals\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * UpdateTerminalRequest - Validation for Updating Terminals
 *
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Optional Fields (partial update):
 * - name: Terminal display name (max 100 chars)
 * - is_active: Active status (boolean)
 *
 * At least one field must be provided.
 */
final class UpdateTerminalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.string' => 'Terminal name must be text',
            'name.max' => 'Terminal name cannot exceed 100 characters',
            'is_active.boolean' => 'Active status must be a boolean',
        ];
    }

    /**
     * Custom validation: ensure at least one field is provided.
     *
     * @return array
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $name = $this->input('name');
                $isActive = $this->input('is_active');

                // Check if both are null/empty
                if ($name === null && $isActive === null) {
                    $validator->errors()->add('update', 'At least one field (name or is_active) must be provided');
                }
            },
        ];
    }

    /**
     * Get typed name (nullable).
     *
     * @return string|null
     */
    public function name(): ?string
    {
        return $this->validated('name');
    }

    /**
     * Get typed is_active (nullable).
     *
     * @return bool|null
     */
    public function isActive(): ?bool
    {
        return $this->validated('is_active');
    }

    /**
     * Handle a failed validation attempt.
     * Return JSON error response instead of redirecting.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(
                [
                    'error' => 'validation_failed',
                    'messages' => $validator->errors(),
                ],
                422
            )
        );
    }
}
