<?php

namespace App\Http\Modules\AdminUsers\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * ChangePasswordRequest - Validation for Changing Own Password
 *
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Required Fields:
 * - current_password: Current password (for verification)
 * - new_password: New password (min 8 chars, 1 uppercase, 1 lowercase, 1 digit)
 * - new_password_confirmation: Must match new_password
 *
 * Password Requirements:
 * - Minimum 8 characters
 * - At least 1 uppercase letter (A-Z)
 * - At least 1 lowercase letter (a-z)
 * - At least 1 digit (0-9)
 * - Maximum 255 characters
 */
final class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'min:8', 'max:255'],
            'new_password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
            ],
            'new_password_confirmation' => ['required', 'string', 'same:new_password'],
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
            'current_password.required' => 'Current password is required',
            'current_password.string' => 'Current password must be text',
            'current_password.min' => 'Current password must be at least 8 characters',
            'current_password.max' => 'Current password cannot exceed 255 characters',
            'new_password.required' => 'New password is required',
            'new_password.string' => 'New password must be text',
            'new_password.min' => 'New password must be at least 8 characters',
            'new_password.max' => 'New password cannot exceed 255 characters',
            'new_password.regex' => 'New password must contain at least one uppercase letter, one lowercase letter, and one digit',
            'new_password_confirmation.required' => 'Password confirmation is required',
            'new_password_confirmation.same' => 'Password confirmation must match the new password',
        ];
    }

    /**
     * Get typed current password.
     *
     * @return string
     */
    public function currentPassword(): string
    {
        return $this->validated('current_password');
    }

    /**
     * Get typed new password.
     *
     * @return string
     */
    public function newPassword(): string
    {
        return $this->validated('new_password');
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
