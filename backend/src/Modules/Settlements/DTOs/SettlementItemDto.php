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
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            settlementId: $row['settlement_id'],
            transactionId: $row['transaction_id'],
            memberId: $row['member_id'],
            memberName: isset($row['first_name']) ? ($row['first_name'] . ' ' . ($row['last_name'] ?? '')) : null,
            amountCents: (int) $row['amount_cents'],
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
        ];
    }
}
