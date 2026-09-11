<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

final readonly class SettlementItemDto
{
    public function __construct(
        public string $settlementId,
        public string $transactionId,
        public string $memberId,
        public ?string $memberName,
        public int $amountCents,
        public ?string $transactionType,
        public ?string $notes,
        public ?string $productName,
        public ?string $transactionCreatedAt,
        // Last, with a default, only because PHP will not take an optional
        // parameter before a required one. It belongs beside $productName:
        // everything that prints a product name prints the size after it
        // (ADR-0056).
        public ?int $productVolumeMl = null,
    ) {}

    public static function fromRow(array $row): self
    {
        // Extract product name from multilingual JSON (prefer 'de', fallback to first available)
        $productName = null;
        if (!empty($row['product_names'])) {
            $names = json_decode($row['product_names'], true);
            if (is_array($names)) {
                $productName = $names['de'] ?? $names['en'] ?? reset($names) ?: null;
            }
        }

        return new self(
            settlementId: $row['settlement_id'],
            transactionId: $row['transaction_id'],
            memberId: $row['member_id'],
            memberName: isset($row['first_name']) ? ($row['first_name'] . ' ' . ($row['last_name'] ?? '')) : null,
            amountCents: (int) $row['amount_cents'],
            transactionType: $row['transaction_type'] ?? null,
            notes: $row['transaction_notes'] ?? null,
            productName: $productName,
            // Read live from the same join as the name (ADR-0056 decision 3),
            // and handed over as a number: the client formats it for its own
            // reader, exactly as it does the amount.
            productVolumeMl: isset($row['product_volume_ml']) ? (int) $row['product_volume_ml'] : null,
            transactionCreatedAt: $row['transaction_created_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'settlement_id' => $this->settlementId,
            'transaction_id' => $this->transactionId,
            'member_id' => $this->memberId,
            'member_name' => $this->memberName,
            'amount_cents' => $this->amountCents,
            'amount_eur' => round($this->amountCents / 100, 2),
            'transaction_type' => $this->transactionType,
            'product_name' => $this->productName,
            'product_volume_ml' => $this->productVolumeMl,
            'notes' => $this->notes,
            'transaction_date' => \App\Shared\Utils\DateFormatter::toUtcIso($this->transactionCreatedAt),
        ];
    }
}
