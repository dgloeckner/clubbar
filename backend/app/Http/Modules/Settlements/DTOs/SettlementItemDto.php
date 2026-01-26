<?php

namespace App\Http\Modules\Settlements\DTOs;

/**
 * SettlementItemDto - Line Item in Settlement
 *
 * Represents a single transaction within a settlement.
 * Includes member details and transaction amount.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class SettlementItemDto
{
    public function __construct(
        public string $settlementId,
        public string $transactionId,
        public string $memberId,
        public string $memberName,
        public int $amountCents,
    ) {}

    /**
     * Convert to array for JSON serialization
     *
     * Amount is shown in cents to avoid floating point issues
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'settlement_id' => $this->settlementId,
            'transaction_id' => $this->transactionId,
            'member_id' => $this->memberId,
            'member_name' => $this->memberName,
            'amount_cents' => $this->amountCents,
            'amount_eur' => round($this->amountCents / 100, 2), // For display
        ];
    }
}
