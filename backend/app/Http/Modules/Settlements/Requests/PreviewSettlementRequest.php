<?php

namespace App\Http\Modules\Settlements\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PreviewSettlementRequest - Validation for Settlement Preview
 *
 * Implements Pattern 001: Form Requests for Input Validation
 */
final class PreviewSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'member_id' => ['nullable', 'uuid'],
            'sepa_eligible_only' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_date.date_format' => 'From date must be in YYYY-MM-DD format',
            'to_date.date_format' => 'To date must be in YYYY-MM-DD format',
            'to_date.after_or_equal' => 'To date must be after from date',
            'member_id.uuid' => 'Member ID must be a valid UUID',
        ];
    }

    public function fromDate(): ?string
    {
        return $this->validated('from_date');
    }

    public function toDate(): ?string
    {
        return $this->validated('to_date');
    }

    public function memberId(): ?string
    {
        return $this->validated('member_id');
    }

    public function sepaEligibleOnly(): bool
    {
        return (bool) $this->validated('sepa_eligible_only', false);
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['error' => 'validation_failed', 'messages' => $validator->errors()], 422)
        );
    }
}
