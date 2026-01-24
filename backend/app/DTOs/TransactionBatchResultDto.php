<?php

namespace App\DTOs;

/**
 * TransactionBatchResultDto
 *
 * Data transfer object for batch transaction processing results.
 * Encapsulates accepted transaction IDs and error details for rejected transactions.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class TransactionBatchResultDto
{
    public function __construct(
        public array $acceptedIds,
        public int $rejectedCount,
        public array $errors,
    ) {}

    /**
     * Convert to array for JSON serialization
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
        ];
    }
}
