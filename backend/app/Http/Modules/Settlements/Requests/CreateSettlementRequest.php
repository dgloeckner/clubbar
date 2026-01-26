<?php

namespace App\Http\Modules\Settlements\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Carbon\Carbon;

/**
 * CreateSettlementRequest - Validation for Creating Settlements
 *
 * Implements Pattern 001: Form Requests for Input Validation
 * Implements ADR-0009: Settlement Lead Times (execution_date >= settlement_date + 7)
 */
final class CreateSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settlement_type' => ['required', 'string', 'in:sepa,manual'],
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['uuid'],
            'settlement_date' => ['required', 'date', 'date_format:Y-m-d'],
            'execution_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:settlement_date'],
            'period_start' => ['nullable', 'date', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'manual_reason' => ['nullable', 'string', 'in:cash,bank_transfer,write_off,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'settlement_type.required' => 'Settlement type is required',
            'settlement_type.in' => 'Settlement type must be sepa or manual',
            'transaction_ids.required' => 'At least one transaction is required',
            'transaction_ids.min' => 'At least one transaction is required',
            'transaction_ids.*.uuid' => 'Each transaction ID must be a valid UUID',
            'settlement_date.required' => 'Settlement date is required',
            'settlement_date.date_format' => 'Settlement date must be YYYY-MM-DD format',
            'execution_date.required' => 'Execution date is required',
            'execution_date.date_format' => 'Execution date must be YYYY-MM-DD format',
            'execution_date.after_or_equal' => 'Execution date must be after settlement date',
            'period_start.date_format' => 'Period start must be YYYY-MM-DD format',
            'period_end.date_format' => 'Period end must be YYYY-MM-DD format',
            'period_end.after_or_equal' => 'Period end must be after period start',
            'manual_reason.in' => 'Manual reason must be one of: cash, bank_transfer, write_off, other',
            'notes.max' => 'Notes cannot exceed 1000 characters',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validate execution date is at least 7 days after settlement date (ADR-0009)
            $settlementDate = Carbon::parse($this->settlement_date);
            $executionDate = Carbon::parse($this->execution_date);
            $minimumDate = $settlementDate->copy()->addDays(7);

            if ($executionDate->isBefore($minimumDate)) {
                $validator->errors()->add(
                    'execution_date',
                    "Execution date must be at least 7 days after settlement date. Minimum: {$minimumDate->format('Y-m-d')}"
                );
            }

            // Validate manual_reason is required for manual settlements
            if ($this->settlement_type === 'manual' && empty($this->manual_reason)) {
                $validator->errors()->add('manual_reason', 'Manual reason is required for manual settlements');
            }
        });
    }

    public function settlementType(): string
    {
        return $this->validated('settlement_type');
    }

    public function transactionIds(): array
    {
        return $this->validated('transaction_ids');
    }

    public function settlementDate(): string
    {
        return $this->validated('settlement_date');
    }

    public function executionDate(): string
    {
        return $this->validated('execution_date');
    }

    public function periodStart(): ?string
    {
        return $this->validated('period_start');
    }

    public function periodEnd(): ?string
    {
        return $this->validated('period_end');
    }

    public function manualReason(): ?string
    {
        return $this->validated('manual_reason');
    }

    public function notes(): ?string
    {
        return $this->validated('notes');
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['error' => 'validation_failed', 'messages' => $validator->errors()], 422)
        );
    }
}
