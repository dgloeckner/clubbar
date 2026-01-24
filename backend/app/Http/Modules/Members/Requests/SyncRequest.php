<?php

namespace App\Http\Modules\Members\Requests;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SyncRequest
 *
 * Form request for sync endpoints (members, categories, products).
 * Validates optional `since` query parameter for delta sync.
 *
 * Implements Pattern 001: Form Requests
 */
final class SyncRequest extends FormRequest
{
    /**
     * Define validation rules
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'date_format:Y-m-d\TH:i:s\Z'],
        ];
    }

    /**
     * Get typed `since` parameter for delta sync
     *
     * Defaults to epoch if not provided (full sync from beginning).
     *
     * @return DateTimeImmutable
     */
    public function since(): DateTimeImmutable
    {
        $since = $this->query('since', '1970-01-01T00:00:00Z');
        return new DateTimeImmutable($since);
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
