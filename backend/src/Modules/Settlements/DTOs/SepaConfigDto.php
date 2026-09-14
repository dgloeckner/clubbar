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
        public ?string $mandateTemplateUrl,
        public ?string $mandateReferencePrefix,
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

        // Whether SEPA is ready to actually collect: the creditor identity the
        // bank needs, and the mandate template link a new member is sent to
        // sign (#360) — SepaExportService refuses to export without either.
        $isConfigured = !empty($row['creditor_id'])
            && !empty($row['creditor_name'])
            && !empty($row['creditor_iban'])
            && !empty($row['mandate_template_url']);

        return new self(
            creditorId: $creditorId,
            creditorName: $row['creditor_name'] ?? null,
            creditorIban: $creditorIban,
            creditorAddressStreet: $row['creditor_address_street'] ?? null,
            creditorAddressCity: $row['creditor_address_city'] ?? null,
            creditorAddressCountry: $row['creditor_address_country'] ?? null,
            paymentReferencePrefix: $row['payment_reference_prefix'] ?? null,
            mandateTemplateUrl: $row['mandate_template_url'] ?? null,
            // Not masked and not part of `isConfigured`: it is a label a club
            // chooses, and an install that never touches it mints `CB-…`
            // perfectly well (#936).
            mandateReferencePrefix: $row['mandate_reference_prefix'] ?? null,
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
            'mandate_template_url' => $this->mandateTemplateUrl,
            'mandate_reference_prefix' => $this->mandateReferencePrefix,
            'is_configured' => $this->isConfigured,
        ];
    }
}
