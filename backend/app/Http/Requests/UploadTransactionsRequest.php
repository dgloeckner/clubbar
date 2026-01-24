<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * UploadTransactionsRequest
 *
 * Form request for batch transaction upload.
 * Validates transaction array structure and batch size limits.
 *
 * Implements Pattern 001: Form Requests
 */
final class UploadTransactionsRequest extends FormRequest
{
    /**
     * Define validation rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'transactions' => ['required', 'array', 'min:1', 'max:100'],
            'transactions.*.id' => ['required', 'uuid'],
            'transactions.*.member_id' => ['required', 'uuid'],
            'transactions.*.product_id' => ['required', 'uuid'],
            'transactions.*.amount_cents' => ['required', 'integer', 'min:1'],
            'transactions.*.created_at' => ['required', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/'],
        ];
    }

    /**
     * Handle a failed validation attempt.
     * Return JSON error response instead of redirecting.
     *
     * Distinguishes between:
     * - Structural errors (missing/empty/oversized array) → 400
     * - Field validation errors (invalid format/values) → 422
     *
     * @param Validator $validator
     * @return void
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();

        // Check if errors are structural (on transactions field itself)
        $hasStructuralError = isset($errors['transactions']);

        if ($hasStructuralError) {
            // Structural error: empty array, missing field, or exceeds max size
            // Return first error message in 'message' field
            $message = $errors['transactions'][0] ?? 'Invalid request';

            throw new HttpResponseException(
                response()->json(
                    [
                        'error' => 'invalid_request',
                        'message' => $message,
                    ],
                    400
                )
            );
        }

        // Field validation errors: format, type, etc.
        throw new HttpResponseException(
            response()->json(
                [
                    'error' => 'validation_failed',
                    'details' => collect($errors)->flatten()->all(),
                ],
                422
            )
        );
    }
}
