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
            'notes' => $this->notes,
            'transaction_date' => $this->transactionCreatedAt,
        ];
    }
}
