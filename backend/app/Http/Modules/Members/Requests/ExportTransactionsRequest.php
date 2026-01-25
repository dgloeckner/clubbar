<?php

namespace App\Http\Modules\Members\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * ExportTransactionsRequest
 *
 * Validates query parameters for transaction export endpoint.
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Query Parameters:
 * - from_date: Start date (YYYY-MM-DD, required)
 * - to_date: End date (YYYY-MM-DD, required, >= from_date)
 * - member_id: UUID (optional, must exist)
 * - product_id: UUID (optional, must exist)
 * - type: purchase|correction|all (optional, default: all)
 *
 * Used by: UC-A22 Export Transactions
 */
class ExportTransactionsRequest extends FormRequest
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
            'from_date' => 'required|date_format:Y-m-d',
            'to_date' => 'required|date_format:Y-m-d|after_or_equal:from_date',
            'member_id' => 'nullable|uuid|exists:members,id',
            'product_id' => 'nullable|uuid|exists:products,id',
            'type' => 'nullable|string|in:purchase,correction,all',
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
            'from_date.required' => 'Start date is required',
            'from_date.date_format' => 'Start date must be in YYYY-MM-DD format',
            'to_date.required' => 'End date is required',
            'to_date.date_format' => 'End date must be in YYYY-MM-DD format',
            'to_date.after_or_equal' => 'End date must be equal to or after start date',
            'member_id.uuid' => 'Member ID must be a valid UUID',
            'member_id.exists' => 'Member does not exist',
            'product_id.uuid' => 'Product ID must be a valid UUID',
            'product_id.exists' => 'Product does not exist',
            'type.in' => 'Type must be one of: purchase, correction, all',
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
     * Get filter values from validated request.
     *
     * @return array Filters for export
     */
    public function getFilters(): array
    {
        return [
            'from_date' => $this->query('from_date'),
            'to_date' => $this->query('to_date'),
            'member_id' => $this->query('member_id'),
            'product_id' => $this->query('product_id'),
            'type' => $this->query('type', 'all'),
        ];
    }
}
