<?php

namespace App\Services;

use App\DTOs\TransactionBatchResultDto;
use Illuminate\Support\Facades\DB;

/**
 * TransactionService
 *
 * Handles batch transaction processing and balance calculation.
 * Validates and processes transaction uploads from terminals.
 * Calculates updated member balances after transaction processing.
 *
 * Implements Pattern 004: Service Layer
 * Implements ADR-0004: Immutable Transaction Storage
 * Implements ADR-0023: Terminal Balance State Management
 *
 * Business Logic:
 * - Transactions are immutable (append-only)
 * - Member balance = sum of all unsettled transactions (never deleted)
 * - Balance calculated per-member after batch processing
 * - Returns member_balances in response for terminal sync
 */
final readonly class TransactionService
{
    /**
     * Process batch of transactions
     *
     * Stores transactions and calculates updated member balances.
     * Implements idempotency: duplicate transaction IDs are skipped.
     *
     * @param array $transactions Array of transaction data with keys:
     *                            - id: UUID
     *                            - member_id: UUID
     *                            - product_id: UUID (nullable)
     *                            - amount_cents: int
     *                            - created_at: ISO 8601 datetime
     * @return TransactionBatchResultDto Result with accepted_ids and member_balances
     */
    public function processBatch(array $transactions): TransactionBatchResultDto
    {
        $acceptedIds = [];
        $errors = [];
        $memberIds = [];

        // Step 1: Insert transactions and collect affected members
        foreach ($transactions as $transaction) {
            try {
                // Insert transaction (will fail silently if UUID already exists - idempotency)
                DB::table('transactions')->insertOrIgnore([
                    'id' => $transaction['id'],
                    'member_id' => $transaction['member_id'],
                    'product_id' => $transaction['product_id'] ?? null,
                    'amount_cents' => $transaction['amount_cents'],
                    'transaction_type' => 'purchase',
                    'created_by_terminal_id' => null,
                    'created_at' => $transaction['created_at'],
                ]);

                $acceptedIds[] = $transaction['id'];
                $memberIds[] = $transaction['member_id'];
            } catch (\Exception $e) {
                $errors[] = [
                    'transaction_id' => $transaction['id'] ?? null,
                    'error' => 'database_error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        // Step 2: Calculate member balances for all affected members
        $memberBalances = [];
        foreach (array_unique($memberIds) as $memberId) {
            $balance = DB::table('transactions')
                ->where('member_id', $memberId)
                ->sum('amount_cents');

            $memberBalances[$memberId] = $balance;
        }

        return new TransactionBatchResultDto(
            acceptedIds: $acceptedIds,
            rejectedCount: count($errors),
            errors: $errors,
            memberBalances: $memberBalances,
        );
    }

    /**
     * Get recent transactions for a member
     *
     * Retrieves transaction history for display on terminal.
     * Implements ADR-0024: Transaction History Retrieval (online-only).
     *
     * @param string $memberId Member UUID
     * @param int $limit Maximum transactions to return (default 50)
     * @param int $offset Pagination offset (default 0)
     * @param ?string $since Optional: transactions after this timestamp
     * @return array Array with member_id, count, and transactions[]
     * @throws \Exception If member not found
     */
    public function getRecentTransactions(
        string $memberId,
        int $limit = 50,
        int $offset = 0,
        ?string $since = null
    ): array {
        // Verify member exists
        $memberExists = DB::table('members')
            ->where('id', $memberId)
            ->exists();

        if (!$memberExists) {
            throw new \Exception("Member not found: $memberId", 404);
        }

        // Build query
        $query = DB::table('transactions')
            ->where('member_id', $memberId)
            ->orderByDesc('created_at');

        // Optional: filter by since timestamp
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        // Get total count before pagination
        $totalCount = $query->count();

        // Apply pagination
        $transactions = $query
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(function ($tx) {
                // Fetch product name if product_id is set
                $productName = null;
                if ($tx->product_id) {
                    try {
                        $product = DB::table('products')
                            ->where('id', $tx->product_id)
                            ->select('names')
                            ->first();

                        // TODO: Translate product name to member's preferred language
                        // For now, return first available name
                        if ($product) {
                            $names = json_decode($product->names, true);
                            $productName = reset($names) ?? 'Unknown Product';
                        } else {
                            $productName = 'Product (Deleted)';
                        }
                    } catch (\Exception $e) {
                        // Products table might not exist yet
                        $productName = 'Unknown Product';
                    }
                } else {
                    // Correction transaction - use notes or default message
                    $productName = $tx->notes ? "Correction: {$tx->notes}" : "Correction";
                }

                return [
                    'id' => $tx->id,
                    'amount_cents' => (int) $tx->amount_cents,
                    'type' => $tx->transaction_type,
                    'product_id' => $tx->product_id,
                    'product_name' => $productName,
                    'notes' => $tx->notes,
                    'created_at' => $tx->created_at,
                    'created_by_terminal_id' => $tx->created_by_terminal_id,
                    'created_by_admin_id' => $tx->created_by_admin_id,
                    'related_transaction_id' => $tx->related_transaction_id,
                ];
            })
            ->toArray();

        return [
            'member_id' => $memberId,
            'count' => $totalCount,
            'transactions' => $transactions,
        ];
    }
}
