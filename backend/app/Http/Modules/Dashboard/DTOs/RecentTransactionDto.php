<?php

namespace App\Http\Modules\Dashboard\DTOs;

/**
 * RecentTransactionDto
 *
 * Represents a single transaction in the recent transactions list.
 * Includes member name and product name for display purposes.
 *
 * Read-only immutable DTO.
 */
readonly class RecentTransactionDto
{
    public function __construct(
        public string $id,
        public string $member_id,
        public string $member_name,
        public string $type,
        public int $amount_cents,
        public string $product_name,
        public string $timestamp,
    ) {}

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member_name' => $this->member_name,
            'type' => $this->type,
            'amount_cents' => $this->amount_cents,
            'product_name' => $this->product_name,
            'timestamp' => $this->timestamp,
        ];
    }
}
