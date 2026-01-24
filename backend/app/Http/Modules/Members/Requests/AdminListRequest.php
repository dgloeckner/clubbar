<?php

namespace App\Http\Modules\Members\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AdminListRequest
 *
 * Form request for listing members via admin API.
 * Validates pagination parameters and optional filters.
 *
 * Implements Pattern 001: Form Requests
 */
final class AdminListRequest extends FormRequest
{
    /**
     * Define validation rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'filters.is_active' => ['sometimes', 'string', 'in:true,false'],
            'filters.language' => ['sometimes', 'string', 'in:de,en,fr'],
        ];
    }

    /**
     * Get limit for pagination (default 20, max 100)
     *
     * @return int
     */
    public function limit(): int
    {
        return min((int) $this->query('limit', 20), 100);
    }

    /**
     * Get offset for pagination (default 0)
     *
     * @return int
     */
    public function offset(): int
    {
        return max((int) $this->query('offset', 0), 0);
    }

    /**
     * Get is_active filter (nullable)
     *
     * @return bool|null
     */
    public function filterIsActive(): ?bool
    {
        $filter = $this->query('filters')['is_active'] ?? null;
        if ($filter === null) {
            return null;
        }
        // Handle string 'true'/'false' from query parameters
        if (is_string($filter)) {
            return filter_var($filter, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) $filter;
    }

    /**
     * Get language filter (nullable)
     *
     * @return string|null
     */
    public function filterLanguage(): ?string
    {
        return $this->query('filters')['language'] ?? null;
    }

    /**
     * Get all filters as array
     *
     * @return array
     */
    public function filters(): array
    {
        return array_filter([
            'is_active' => $this->filterIsActive(),
            'language' => $this->filterLanguage(),
        ], fn($value) => $value !== null);
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
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(
                [
                    'error' => 'invalid_request',
                    'messages' => $validator->errors(),
                ],
                400
            )
        );
    }
}
