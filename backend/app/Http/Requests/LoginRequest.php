<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LoginRequest - Admin Login Validation
 *
 * Implements Pattern 001: Form Requests for Input Validation
 * Validates admin login credentials (email and password).
 */
class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Login endpoint is public - no authorization check needed.
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
            'email' => ['required', 'email', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * Get the validated email (typed accessor).
     *
     * @return string
     */
    public function email(): string
    {
        return $this->validated()['email'];
    }

    /**
     * Get the validated password (typed accessor).
     *
     * @return string
     */
    public function password(): string
    {
        return $this->validated()['password'];
    }
}
