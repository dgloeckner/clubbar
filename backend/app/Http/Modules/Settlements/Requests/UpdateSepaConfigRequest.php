<?php

namespace App\Http\Modules\Settlements\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * UpdateSepaConfigRequest - Validation for SEPA Configuration
 *
 * Implements Pattern 001: Form Requests for Input Validation
 * Implements ADR-0005: IBAN Storage and ADR-0007: SEPA Configuration
 *
 * IBAN validation:
 * - Standard SEPA format (up to 34 characters)
 * - Mod-97 checksum validation
 * - Uppercase letters only
 */
final class UpdateSepaConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'creditor_id' => ['nullable', 'string', 'max:35'],
            'creditor_name' => ['nullable', 'string', 'max:70'],
            'creditor_iban' => ['nullable', 'string', 'max:34'],
            'creditor_address_street' => ['nullable', 'string', 'max:70'],
            'creditor_address_city' => ['nullable', 'string', 'max:35'],
            'creditor_address_country' => ['nullable', 'string', 'size:2'],
        ];
    }

    public function messages(): array
    {
        return [
            'creditor_id.max' => 'Creditor ID cannot exceed 35 characters',
            'creditor_name.max' => 'Creditor name cannot exceed 70 characters',
            'creditor_iban.max' => 'IBAN cannot exceed 34 characters',
            'creditor_address_street.max' => 'Street address cannot exceed 70 characters',
            'creditor_address_city.max' => 'City cannot exceed 35 characters',
            'creditor_address_country.size' => 'Country code must be 2 characters (ISO 3166-1 alpha-2)',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validate IBAN checksum if provided
            if ($this->has('creditor_iban') && !empty($this->creditor_iban)) {
                if (!$this->isValidIban($this->creditor_iban)) {
                    $validator->errors()->add('creditor_iban', 'IBAN checksum is invalid');
                }
            }
        });
    }

    /**
     * Validate IBAN using mod-97 algorithm
     *
     * @param string $iban
     * @return bool
     */
    private function isValidIban(string $iban): bool
    {
        // Remove spaces and convert to uppercase
        $iban = strtoupper(str_replace(' ', '', $iban));

        // Check length (typically 15-34 characters)
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }

        // Check format: starts with 2 letters, followed by 2 digits
        if (!preg_match('/^[A-Z]{2}[0-9]{2}/', $iban)) {
            return false;
        }

        // Validate checksum using mod-97
        // Move first 4 characters to end and replace letters with numbers (A=10, B=11, etc.)
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numericIban = '';

        foreach (str_split($rearranged) as $char) {
            if (is_numeric($char)) {
                $numericIban .= $char;
            } else {
                $numericIban .= ord($char) - ord('A') + 10;
            }
        }

        // Check mod-97
        return (int) bcmod($numericIban, 97) === 1;
    }

    public function creditorId(): ?string
    {
        return $this->validated('creditor_id');
    }

    public function creditorName(): ?string
    {
        return $this->validated('creditor_name');
    }

    public function creditorIban(): ?string
    {
        return $this->validated('creditor_iban');
    }

    public function creditorAddressStreet(): ?string
    {
        return $this->validated('creditor_address_street');
    }

    public function creditorAddressCity(): ?string
    {
        return $this->validated('creditor_address_city');
    }

    public function creditorAddressCountry(): ?string
    {
        return $this->validated('creditor_address_country');
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['error' => 'validation_failed', 'messages' => $validator->errors()], 422)
        );
    }
}
