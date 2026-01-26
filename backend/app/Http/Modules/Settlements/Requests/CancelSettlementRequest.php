<?php

namespace App\Http\Modules\Settlements\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * CancelSettlementRequest - Validation for Cancelling Settlements
 *
 * Implements Pattern 001: Form Requests for Input Validation
 */
final class CancelSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.string' => 'Reason must be text',
            'reason.max' => 'Reason cannot exceed 500 characters',
        ];
    }

    public function reason(): ?string
    {
        return $this->validated('reason');
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['error' => 'validation_failed', 'messages' => $validator->errors()], 422)
        );
    }
}
