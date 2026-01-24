<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'transactions.*.created_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
        ];
    }
}
