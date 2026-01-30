<?php

namespace App\Http\Modules\Transactions\Services;

use App\Http\Modules\Transactions\DTOs\TransactionBatchResultDto;
use Illuminate\Support\Facades\DB;

/**
 * TransactionsService
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
final readonly class TransactionsService
{
    /**
     * Initialize service with repository dependency.
     * Will be injected by Service Provider (Pattern 008).
     *
     * @param TransactionsRepository $repository
     */
    public function __construct(
        private readonly TransactionsRepository $repository,
    ) {}

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
                // Validate member exists and has valid SEPA
                $member = DB::table('members')
                    ->where('id', $transaction['member_id'])
                    ->select('id', 'iban', 'mandate_reference')
                    ->first();

                if (!$member) {
                    $errors[] = [
                        'transaction_id' => $transaction['id'],
                        'error' => 'member_not_found',
                        'message' => 'Member does not exist',
                    ];
                    continue;
                }

                $isSepaValid = !empty($member->iban) && !empty($member->mandate_reference);
                if (!$isSepaValid) {
                    $errors[] = [
                        'transaction_id' => $transaction['id'],
                        'error' => 'sepa_invalid',
                        'message' => 'Member does not have valid SEPA mandate. Transactions cannot be created without IBAN and mandate reference.',
                    ];
                    continue;
                }

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

            // Cast to int (DB returns as string in JSON)
            $memberBalances[$memberId] = (int) $balance;
        }

        return new TransactionBatchResultDto(
            acceptedIds: $acceptedIds,
            rejectedCount: count($errors),
            errors: $errors,
            memberBalances: $memberBalances,
        );
    }

    /**
     * Record manual correction transaction.
     *
     * Creates a correction transaction (manual booking by admin).
     * Implements UC-A21: Manual Booking
     * Implements ADR-0004: Immutable Transaction Storage
     *
     * @param string $memberId Member UUID
     * @param int $amountCents Amount in cents (positive=charge, negative=credit)
     * @param string $reason Description/reason for correction (max 255 chars)
     * @param ?string $adminId Admin user UUID (nullable)
     * @return array Created transaction record
     * @throws \Exception If member not found
     */
    public function recordCorrection(
        string $memberId,
        int $amountCents,
        string $reason,
        ?string $adminId = null
    ): array {
        // Verify member exists
        $member = DB::table('members')
            ->where('id', $memberId)
            ->select('id', 'iban', 'mandate_reference')
            ->first();

        if (!$member) {
            throw new \Exception("Member not found: $memberId", 404);
        }

        // Validate SEPA
        $isSepaValid = !empty($member->iban) && !empty($member->mandate_reference);
        if (!$isSepaValid) {
            throw new \Exception(
                'Member does not have valid SEPA mandate. Please update member IBAN and mandate reference before creating transactions.',
                422
            );
        }

        // Create correction transaction
        $transactionId = \Illuminate\Support\Str::uuid()->toString();
        DB::table('transactions')->insert([
            'id' => $transactionId,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => $amountCents,
            'transaction_type' => 'correction',
            'notes' => $reason,
            'related_transaction_id' => null,
            'created_by_terminal_id' => null,
            'created_by_admin_id' => $adminId,
            'created_at' => now(),
        ]);

        // Return created transaction
        return [
            'id' => $transactionId,
            'member_id' => $memberId,
            'amount_cents' => $amountCents,
            'transaction_type' => 'correction',
            'notes' => $reason,
            'created_by_admin_id' => $adminId,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Export transactions as CSV.
     *
     * Generates CSV with transactions filtered by date range and optional filters.
     * Implements UC-A22: Export Transactions
     *
     * @param string $fromDate Start date (Y-m-d format)
     * @param string $toDate End date (Y-m-d format)
     * @param ?string $memberId Filter by member (optional)
     * @param ?string $productId Filter by product (optional)
     * @param string $type Filter by type: purchase|correction|all (default: all)
     * @return array Array with csv_content and filename
     */
    public function exportTransactions(
        string $fromDate,
        string $toDate,
        ?string $memberId = null,
        ?string $productId = null,
        string $type = 'all'
    ): array {
        // Build query
        $query = DB::table('transactions')
            ->whereBetween('created_at', [
                $fromDate . ' 00:00:00',
                $toDate . ' 23:59:59',
            ]);

        // Apply filters
        if ($memberId) {
            $query->where('member_id', $memberId);
        }
        if ($productId) {
            $query->where('product_id', $productId);
        }
        if ($type !== 'all') {
            $query->where('transaction_type', $type);
        }

        // Order by created_at DESC
        $transactions = $query
            ->orderByDesc('created_at')
            ->get();

        // Generate CSV content
        $csv = "date;member_name;product;type;amount\n";

        foreach ($transactions as $tx) {
            // Get member name
            $member = DB::table('members')
                ->where('id', $tx->member_id)
                ->select('first_name', 'last_name')
                ->first();
            $memberName = 'Unknown Member';
            if ($member) {
                $memberName = trim("{$member->first_name} {$member->last_name}");
                if (empty($memberName)) {
                    $memberName = 'Unknown Member';
                }
            }

            // Get product name (if product_id is set)
            $productName = '';
            if ($tx->product_id) {
                $product = DB::table('products')
                    ->where('id', $tx->product_id)
                    ->select('names')
                    ->first();

                if ($product) {
                    $names = json_decode($product->names, true);
                    $productName = reset($names) ?? 'Unknown Product';
                } else {
                    $productName = 'Product (Deleted)';
                }
            }

            // Convert amount_cents to EUR decimal
            $amountEur = number_format($tx->amount_cents / 100, 2, '.', '');

            // CSV line
            $line = sprintf(
                "%s;%s;%s;%s;%s\n",
                $tx->created_at,
                $memberName,
                $productName,
                $tx->transaction_type,
                $amountEur
            );

            $csv .= $line;
        }

        // Generate filename
        $filename = sprintf(
            'transactions-%s-to-%s.csv',
            $fromDate,
            $toDate
        );

        return [
            'csv_content' => $csv,
            'filename' => $filename,
        ];
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
    /**
     * Get member transaction history with current balance
     *
     * Retrieves all transactions for a member with optional filtering by type.
     * Returns member's current balance calculated from all transactions.
     *
     * @param string $memberId Member UUID
     * @param ?string $type Optional filter: 'purchase' | 'correction' | null for all
     * @return array Transaction history with member_id, current_balance_cents, transactions, settlements
     */
    public function getMemberTransactionHistory(
        string $memberId,
        ?string $type = null
    ): array {
        // Verify member exists
        $memberExists = DB::table('members')
            ->where('id', $memberId)
            ->exists();

        if (!$memberExists) {
            throw new \Exception("Member not found: $memberId", 404);
        }

        // Get member's current balance
        $currentBalance = DB::table('transactions')
            ->where('member_id', $memberId)
            ->sum('amount_cents') ?? 0;

        // Get all transactions
        $query = DB::table('transactions')
            ->where('member_id', $memberId)
            ->orderByDesc('created_at');

        // Filter by type if specified
        if ($type && $type !== 'all') {
            $query->where('transaction_type', $type);
        }

        $transactions = $query
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'date' => $tx->created_at,
                    'type' => $tx->transaction_type === 'correction' ? 'correction' : 'purchase',
                    'description' => $tx->notes ?? ($tx->transaction_type === 'correction' ? 'Correction' : 'Purchase'),
                    'amount_cents' => (int) $tx->amount_cents,
                    'running_total_cents' => 0, // Will be calculated below
                ];
            })
            ->toArray();

        // Calculate running totals (reverse order since we fetched DESC)
        $runningTotal = 0;
        foreach (array_reverse($transactions) as &$tx) {
            $runningTotal += $tx['amount_cents'];
            $tx['running_total_cents'] = $runningTotal;
        }
        // Reverse back to DESC order
        $transactions = array_reverse($transactions);

        return [
            'member_id' => $memberId,
            'current_balance_cents' => (int) $currentBalance,
            'transactions' => $transactions,
            'settlements' => [], // TODO: Implement settlements when settlement module is available
        ];
    }

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

    /**
     * Get global transactions list with pagination, filtering, and sorting.
     *
     * Returns paginated list of all transactions with optional filtering and sorting.
     * Used for admin journal view across all members.
     * Implements UC-A22-B: Filter Transactions by Settlement Status
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Items per page (1-100)
     * @param ?string $dateFrom Start date (ISO format Y-m-d, optional)
     * @param ?string $dateTo End date (ISO format Y-m-d, optional)
     * @param string $type Filter by type (all|purchase|correction, default: all)
     * @param ?string $memberId Filter by member UUID (optional)
     * @param ?string $search Search member name or notes (optional)
     * @param string $sortKey Sort field (created_at|amount|type|member, default: created_at)
     * @param string $sortOrder Sort order (asc|desc, default: desc)
     * @param string $settlementStatus Filter by settlement status (all|open|settled, default: all)
     * @return array Response with items, total, page, per_page
     */
    public function getTransactions(
        int $page = 1,
        int $perPage = 20,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $type = 'all',
        ?string $memberId = null,
        ?string $search = null,
        string $sortKey = 'created_at',
        string $sortOrder = 'desc',
        string $settlementStatus = 'all'
    ): array {
        // Ensure page is at least 1
        $page = max(1, $page);
        // Ensure per_page is between 1 and 100
        $perPage = max(1, min(100, $perPage));

        // Build query
        $query = DB::table('transactions')
            ->join('members', 'transactions.member_id', '=', 'members.id')
            ->select([
                'transactions.id',
                'transactions.member_id',
                'transactions.product_id',
                'transactions.amount_cents',
                'transactions.transaction_type',
                'transactions.notes',
                'transactions.created_at',
                'transactions.created_by_admin_id',
                'transactions.created_by_terminal_id',
                DB::raw('(
                    SELECT s.settlement_date
                    FROM settlement_items si
                    JOIN settlements s ON si.settlement_id = s.id
                    WHERE si.transaction_id = transactions.id
                      AND s.is_cancelled = false
                    LIMIT 1
                ) as settlement_date'),
                DB::raw('(
                    CASE WHEN EXISTS (
                        SELECT 1 FROM settlement_items si
                        JOIN settlements s ON si.settlement_id = s.id
                        WHERE si.transaction_id = transactions.id
                          AND s.is_cancelled = false
                    ) THEN 1 ELSE 0 END
                ) as is_settled'),
                DB::raw("CONCAT(members.first_name, ' ', members.last_name) AS member_name"),
            ]);

        // Apply type filter
        if ($type !== 'all') {
            $query->where('transactions.transaction_type', $type);
        }

        // Apply member filter
        if ($memberId) {
            $query->where('transactions.member_id', $memberId);
        }

        // Apply date range filter
        if ($dateFrom) {
            $query->whereDate('transactions.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('transactions.created_at', '<=', $dateTo);
        }

        // Apply search filter (member name or transaction notes)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where(DB::raw("CONCAT(members.first_name, ' ', members.last_name)"), 'LIKE', "%{$search}%")
                  ->orWhere('transactions.notes', 'LIKE', "%{$search}%");
            });
        }

        // Apply settlement status filter
        if ($settlementStatus === 'open') {
            $query->whereNotExists(function ($subquery) {
                $subquery->select(DB::raw(1))
                    ->from('settlement_items')
                    ->join('settlements', 'settlement_items.settlement_id', '=', 'settlements.id')
                    ->where('settlements.is_cancelled', false)
                    ->whereColumn('settlement_items.transaction_id', '=', 'transactions.id');
            });
        } elseif ($settlementStatus === 'settled') {
            $query->whereExists(function ($subquery) {
                $subquery->select(DB::raw(1))
                    ->from('settlement_items')
                    ->join('settlements', 'settlement_items.settlement_id', '=', 'settlements.id')
                    ->where('settlements.is_cancelled', false)
                    ->whereColumn('settlement_items.transaction_id', '=', 'transactions.id');
            });
        }
        // 'all' = no filter

        // Get total count before pagination
        $total = $query->count();

        // Apply sorting
        if ($sortKey === 'member') {
            // Sort by member name
            if ($sortOrder === 'asc') {
                $query->orderBy(DB::raw("CONCAT(members.first_name, ' ', members.last_name)"), 'asc');
            } else {
                $query->orderBy(DB::raw("CONCAT(members.first_name, ' ', members.last_name)"), 'desc');
            }
        } elseif ($sortKey === 'amount') {
            // Sort by amount
            $query->orderBy('transactions.amount_cents', $sortOrder);
        } elseif ($sortKey === 'type') {
            // Sort by transaction type
            $query->orderBy('transactions.transaction_type', $sortOrder);
        } else {
            // Default: sort by created_at
            $query->orderBy('transactions.created_at', $sortOrder);
        }

        // Apply pagination
        $offset = ($page - 1) * $perPage;
        $transactions = $query
            ->limit($perPage)
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

                        if ($product) {
                            $names = json_decode($product->names, true);
                            $productName = reset($names) ?? 'Unknown Product';
                        } else {
                            $productName = 'Product (Deleted)';
                        }
                    } catch (\Exception $e) {
                        $productName = 'Unknown Product';
                    }
                }

                return [
                    'id' => $tx->id,
                    'member_id' => $tx->member_id,
                    'member_name' => trim($tx->member_name ?? ''),
                    'type' => $tx->transaction_type,
                    'amount_cents' => (int) $tx->amount_cents,
                    'description' => $tx->notes ?? ($tx->transaction_type === 'correction' ? 'Correction' : ''),
                    'product_id' => $tx->product_id,
                    'product_name' => $productName,
                    'created_at' => $tx->created_at,
                    'created_by_admin_id' => $tx->created_by_admin_id,
                    'created_by_terminal_id' => $tx->created_by_terminal_id,
                    'is_settled' => (bool) $tx->is_settled,
                    'settlement_date' => $tx->settlement_date,
                ];
            })
            ->toArray();

        return [
            'items' => $transactions,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
