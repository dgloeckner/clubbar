<?php

namespace App\Http\Modules\Transactions\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * GetTransactionsRequest
 *
 * Validates query parameters for global transactions listing endpoint.
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Query Parameters:
 * - page: integer, 1-indexed (default: 1)
 * - per_page: integer, items per page (default: 20, max: 100)
 * - date_from: ISO date (YYYY-MM-DD), optional
 * - date_to: ISO date (YYYY-MM-DD), optional, >= date_from
 * - type: purchase|correction|all (default: all)
 * - member_id: UUID (optional, filter by member)
 * - search: string (optional, search member name or notes)
 * - sort: created_at|amount|type|member (default: created_at)
 * - order: asc|desc (default: desc)
 * - settlement_status: all|open|settled (default: all)
 *
 * Used by: Global transactions journal (UC-A20 derivative)
 * Implements: UC-A22-B Filter Transactions by Settlement Status
 */
class GetTransactionsRequest extends FormRequest
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
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'type' => 'nullable|string|in:all,purchase,correction',
            'member_id' => 'nullable|uuid|exists:members,id',
            'search' => 'nullable|string|max:255',
            'sort' => 'nullable|string|in:created_at,amount,type,member',
            'order' => 'nullable|string|in:asc,desc',
            'settlement_status' => 'nullable|string|in:all,open,settled',
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
            'page.integer' => 'Page must be a number',
            'page.min' => 'Page must be at least 1',
            'per_page.integer' => 'Per page must be a number',
            'per_page.min' => 'Per page must be at least 1',
            'per_page.max' => 'Per page cannot exceed 100',
            'date_from.date_format' => 'Start date must be in YYYY-MM-DD format',
            'date_to.date_format' => 'End date must be in YYYY-MM-DD format',
            'date_to.after_or_equal' => 'End date must be equal to or after start date',
            'type.in' => 'Type must be one of: all, purchase, correction',
            'member_id.uuid' => 'Member ID must be a valid UUID',
            'member_id.exists' => 'Member does not exist',
            'search.max' => 'Search string cannot exceed 255 characters',
            'sort.in' => 'Sort must be one of: created_at, amount, type, member',
            'order.in' => 'Order must be asc or desc',
            'settlement_status.in' => 'Settlement status must be one of: all, open, settled',
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
     * Get pagination parameters with defaults.
     *
     * @return array ['page' => int, 'per_page' => int]
     */
    public function getPagination(): array
    {
        return [
            'page' => max(1, (int) $this->query('page', 1)),
            'per_page' => max(1, min(100, (int) $this->query('per_page', 20))),
        ];
    }

    /**
     * Get filter values with defaults.
     *
     * @return array Filters for query
     */
    public function getFilters(): array
    {
        return [
            'date_from' => $this->query('date_from'),
            'date_to' => $this->query('date_to'),
            'type' => $this->query('type', 'all'),
            'member_id' => $this->query('member_id'),
            'search' => $this->query('search'),
            'settlement_status' => $this->query('settlement_status', 'all'),
        ];
    }

    /**
     * Get sort parameters with defaults.
     *
     * @return array ['sort' => string, 'order' => string]
     */
    public function getSort(): array
    {
        return [
            'sort' => $this->query('sort', 'created_at'),
            'order' => $this->query('order', 'desc'),
        ];
    }
}
