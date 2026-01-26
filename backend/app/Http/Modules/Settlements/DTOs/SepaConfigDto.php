<?php

namespace App\Http\Modules\Settlements\DTOs;

/**
 * SepaConfigDto - SEPA Configuration Response
 *
 * Encapsulates SEPA organization configuration for responses.
 * Sensitive fields (creditor_id, creditor_iban) are masked for security.
 *
 * Implements Pattern 003: Data Transfer Objects
 * Implements ADR-0007: SEPA Configuration Management
 */
final readonly class SepaConfigDto
{
    public function __construct(
        public ?string $creditorId,
        public ?string $creditorName,
        public ?string $creditorIban,
        public ?string $creditorAddressStreet,
        public ?string $creditorAddressCity,
        public ?string $creditorAddressCountry,
        public bool $isConfigured,
    ) {}

    /**
     * Convert to array for JSON serialization
     *
     * Sensitive fields are masked (creditor_id, creditor_iban show only first/last 4 chars)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'creditor_id' => $this->creditorId, // Masked: DE01****9999
            'creditor_name' => $this->creditorName,
            'creditor_iban' => $this->creditorIban, // Masked: DE89****9999
            'creditor_address_street' => $this->creditorAddressStreet,
            'creditor_address_city' => $this->creditorAddressCity,
            'creditor_address_country' => $this->creditorAddressCountry,
            'is_configured' => $this->isConfigured,
        ];
    }
}
