<?php

namespace App\Http\Modules\Terminals\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * CreateTerminalRequest - Validation for Creating Terminals
 *
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Required Fields:
 * - name: Terminal display name (max 100 chars)
 * - device_id: Unique device identifier (max 100 chars, e.g., hardware serial number)
 *
 * API token is auto-generated (not submitted).
 */
final class CreateTerminalRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'device_id' => ['required', 'string', 'max:100', 'unique:terminals,device_id'],
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
            'name.required' => 'Terminal name is required',
            'name.string' => 'Terminal name must be text',
            'name.max' => 'Terminal name cannot exceed 100 characters',
            'device_id.required' => 'Device ID is required',
            'device_id.string' => 'Device ID must be text',
            'device_id.max' => 'Device ID cannot exceed 100 characters',
            'device_id.unique' => 'This device ID is already registered',
        ];
    }

    /**
     * Get typed name.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->validated('name');
    }

    /**
     * Get typed device ID.
     *
     * @return string
     */
    public function deviceId(): string
    {
        return $this->validated('device_id');
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
