<?php

namespace App\Http\Modules\Products\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ListProductsRequest
 *
 * Validates query parameters for product listing endpoint.
 * Implements Pattern 001: Form Requests for Input Validation
 *
 * Query Parameters:
 * - page: Page number (1+)
 * - per_page: Results per page (default 20, max 100)
 * - status: all|active|inactive
 * - category_id: UUID of category
 * - search: Text search in product names
 * - sort: name|price|category|created_at
 * - order: asc|desc
 */
class ListProductsRequest extends FormRequest
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
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:all,active,inactive',
            'category_id' => 'nullable|uuid|exists:categories,id',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|string|in:name,price,category,created_at',
            'order' => 'nullable|string|in:asc,desc',
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
            'page.min' => 'Page must be 1 or greater',
            'per_page.integer' => 'Results per page must be a number',
            'per_page.min' => 'Results per page must be 1 or greater',
            'per_page.max' => 'Results per page cannot exceed 100',
            'status.in' => 'Status must be one of: all, active, inactive',
            'category_id.uuid' => 'Category ID must be a valid UUID',
            'category_id.exists' => 'Category does not exist',
            'search.max' => 'Search query cannot exceed 100 characters',
            'sort.in' => 'Sort field must be one of: name, price, category, created_at',
            'order.in' => 'Sort order must be asc or desc',
        ];
    }

    /**
     * Get typed filter values from validated request.
     *
     * @return array Filters for repository
     */
    public function getFilters(): array
    {
        return [
            'status' => $this->query('status', 'all'),
            'category_id' => $this->query('category_id'),
            'search' => $this->query('search'),
        ];
    }

    /**
     * Get pagination parameters.
     *
     * @return array{page: int, per_page: int}
     */
    public function getPagination(): array
    {
        return [
            'page' => max(1, $this->query('page', 1)),
            'per_page' => min(100, max(1, $this->query('per_page', 20))),
        ];
    }

    /**
     * Get sort parameters.
     *
     * @return array{sort: string, order: string}
     */
    public function getSort(): array
    {
        return [
            'sort' => $this->query('sort', 'category'),
            'order' => $this->query('order', 'asc'),
        ];
    }
}
