<?php

namespace App\Http\Modules\Transactions\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * CreateCorrectionRequest
 *
 * Validates input for manual transaction correction/adjustment.
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Required Fields:
 * - amount_cents: Integer (positive=charge, negative=credit), non-zero
 * - reason: String (max 255 chars), required
 *
 * Used by: UC-A21 Manual Booking
 */
class CreateCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization checked by middleware (AuthenticateAdminSession).
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
            'amount_cents' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:255',
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_cents.required' => 'Amount is required',
            'amount_cents.integer' => 'Amount must be a whole number (in cents)',
            'amount_cents.not_in' => 'Amount cannot be zero',
            'reason.required' => 'Reason is required',
            'reason.string' => 'Reason must be text',
            'reason.max' => 'Reason cannot exceed 255 characters',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * Returns JSON error response instead of redirect.
     *
     * @param Validator $validator
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }

    /**
     * Get typed accessor methods for validated data.
     *
     * @return int Amount in cents
     */
    public function amountCents(): int
    {
        return (int) $this->validated('amount_cents');
    }

    /**
     * @return string Reason for correction
     */
    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
