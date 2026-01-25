<?php

namespace App\Http\Modules\AdminUsers\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * CreateAdminUserRequest - Validation for Creating Admin Users
 *
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Required Fields:
 * - email: Unique email address
 * - display_name: Admin display name (min 2 chars)
 * - locale: Preferred language (de, en, fr)
 *
 * Password is auto-generated (not submitted).
 */
final class CreateAdminUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'string', 'max:255', 'unique:admin_users,email'],
            'display_name' => ['required', 'string', 'min:2', 'max:255'],
            'locale' => ['required', 'string', 'in:de,en,fr'],
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
            'email.required' => 'Email is required',
            'email.email' => 'Email must be valid',
            'email.unique' => 'This email address is already registered',
            'email.max' => 'Email cannot exceed 255 characters',
            'display_name.required' => 'Display name is required',
            'display_name.string' => 'Display name must be text',
            'display_name.min' => 'Display name must be at least 2 characters',
            'display_name.max' => 'Display name cannot exceed 255 characters',
            'locale.required' => 'Preferred language is required',
            'locale.in' => 'Preferred language must be one of: de, en, fr',
        ];
    }

    /**
     * Get typed email.
     *
     * @return string
     */
    public function email(): string
    {
        return $this->validated('email');
    }

    /**
     * Get typed display name.
     *
     * @return string
     */
    public function displayName(): string
    {
        return $this->validated('display_name');
    }

    /**
     * Get typed locale.
     *
     * @return string
     */
    public function locale(): string
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
