<?php

namespace App\Http\Modules\AdminUsers\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * UpdateAdminUserRequest - Validation for Updating Admin Users
 *
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Optional Fields (partial update):
 * - email: Must be unique (excluding current admin)
 * - display_name: Admin display name (min 2 chars)
 * - locale: Preferred language (de, en, fr)
 */
final class UpdateAdminUserRequest extends FormRequest
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
            'email' => [
                'nullable',
                'email',
                'string',
                'max:255',
                Rule::unique('admin_users', 'email')->ignore($this->route('adminId')),
            ],
            'display_name' => ['nullable', 'string', 'min:2', 'max:255'],
            'locale' => ['nullable', 'string', 'in:de,en,fr'],
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
            'email.email' => 'Email must be valid',
            'email.unique' => 'This email address is already registered',
            'email.max' => 'Email cannot exceed 255 characters',
            'display_name.string' => 'Display name must be text',
            'display_name.min' => 'Display name must be at least 2 characters',
            'display_name.max' => 'Display name cannot exceed 255 characters',
            'locale.in' => 'Preferred language must be one of: de, en, fr',
        ];
    }

    /**
     * Get typed email (nullable).
     *
     * @return string|null
     */
    public function email(): ?string
    {
        return $this->validated('email');
    }

    /**
     * Get typed display name (nullable).
     *
     * @return string|null
     */
    public function displayName(): ?string
    {
        return $this->validated('display_name');
    }

    /**
     * Get typed locale (nullable).
     *
     * @return string|null
     */
    public function locale(): ?string
    {
        return $this->validated('locale');
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
