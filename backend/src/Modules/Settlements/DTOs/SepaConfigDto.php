<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

final readonly class SepaConfigDto
{
    public function __construct(
        public ?string $creditorId,
        public ?string $creditorName,
        public ?string $creditorIban,
        public ?string $creditorAddressStreet,
        public ?string $creditorAddressCity,
        public ?string $creditorAddressCountry,
        public ?string $paymentReferencePrefix,
        public bool $isConfigured,
    ) {}

    public static function fromRow(array $row, bool $masked = false): self
    {
        $creditorId = $row['creditor_id'] ?? null;
        $creditorIban = $row['creditor_iban'] ?? null;

        if ($masked) {
            $creditorId = self::maskString($creditorId);
            $creditorIban = self::maskString($creditorIban);
        }

        $isConfigured = !empty($row['creditor_id'])
            && !empty($row['creditor_name'])
            && !empty($row['creditor_iban']);

        return new self(
            creditorId: $creditorId,
            creditorName: $row['creditor_name'] ?? null,
            creditorIban: $creditorIban,
            creditorAddressStreet: $row['creditor_address_street'] ?? null,
            creditorAddressCity: $row['creditor_address_city'] ?? null,
            creditorAddressCountry: $row['creditor_address_country'] ?? null,
            paymentReferencePrefix: $row['payment_reference_prefix'] ?? null,
            isConfigured: $isConfigured,
        );
    }

    private static function maskString(?string $value): ?string
    {
        if ($value === null || strlen($value) < 8) {
            return $value ? '****' : null;
        }
        return substr($value, 0, 4) . '****' . substr($value, -4);
    }

    public function toArray(): array
    {
        return [
            'creditor_id' => $this->creditorId,
            'creditor_name' => $this->creditorName,
            'creditor_iban' => $this->creditorIban,
            'creditor_address_street' => $this->creditorAddressStreet,
            'creditor_address_city' => $this->creditorAddressCity,
            'creditor_address_country' => $this->creditorAddressCountry,
            'payment_reference_prefix' => $this->paymentReferencePrefix,
            'is_configured' => $this->isConfigured,
        ];
    }
}
