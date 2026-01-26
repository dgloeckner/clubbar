<?php

namespace App\Http\Modules\Dashboard\DTOs;

/**
 * SystemStatusDto
 *
 * High-level system status indicators.
 * Includes settlement info, member/transaction counts, and database health.
 *
 * Read-only immutable DTO.
 */
readonly class SystemStatusDto
{
    public function __construct(
        public ?string $last_settlement_date,
        public int $pending_settlement_count,
        public int $total_members,
        public int $total_transactions,
        public string $database_health,
    ) {}

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'last_settlement_date' => $this->last_settlement_date,
            'pending_settlement_count' => $this->pending_settlement_count,
            'total_members' => $this->total_members,
            'total_transactions' => $this->total_transactions,
            'database_health' => $this->database_health,
        ];
    }
}
