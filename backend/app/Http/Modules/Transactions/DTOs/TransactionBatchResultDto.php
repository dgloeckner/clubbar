<?php

namespace App\Http\Modules\Transactions\DTOs;

/**
 * TransactionBatchResultDto
 *
 * Data transfer object for batch transaction processing results.
 * Encapsulates accepted transaction IDs, error details for rejected transactions,
 * and member balances updated by the batch.
 *
 * Implements Pattern 003: Data Transfer Objects
 * Implements ADR-0023: Terminal Balance State Management
 *
 * member_balances is a mapping of member_id → balance_cents (sum of unsettled transactions)
 */
final readonly class TransactionBatchResultDto
{
    public function __construct(
        public array $acceptedIds,
        public int $rejectedCount,
        public array $errors,
        public array $memberBalances = [],
    ) {}

    /**
     * Convert to array for JSON serialization
     *
     * Response format per OpenAPI spec:
     * - accepted_ids: Array of accepted transaction UUIDs
     * - rejected: Object with count and errors array
     * - member_balances: Object mapping member_id → balance_cents
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'accepted_ids' => $this->acceptedIds,
            'rejected' => [
                'count' => $this->rejectedCount,
                'errors' => $this->errors,
            ],
            'member_balances' => $this->memberBalances,
        ];
    }
}
