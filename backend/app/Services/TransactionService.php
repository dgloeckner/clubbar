<?php

namespace App\Services;

use App\DTOs\TransactionBatchResultDto;

/**
 * TransactionService
 *
 * Handles batch transaction processing.
 * Validates and processes transaction uploads from terminals.
 * Implements Pattern 004: Service Layer.
 *
 * In production, this would:
 * - Perform business rule validation
 * - Store transactions immutably
 * - Handle idempotency via transaction UUIDs
 */
final readonly class TransactionService
{
    /**
     * Process batch of transactions
     *
     * @param array $transactions Array of transaction data
     * @return TransactionBatchResultDto
     */
    public function processBatch(array $transactions): TransactionBatchResultDto
    {
        $acceptedIds = [];
        $errors = [];

        // In mock implementation, accept all valid transactions
        // In production, this would validate business rules and store
        foreach ($transactions as $transaction) {
            $acceptedIds[] = $transaction['id'];
        }

        return new TransactionBatchResultDto(
            acceptedIds: $acceptedIds,
            rejectedCount: count($errors),
            errors: $errors,
        );
    }
}
