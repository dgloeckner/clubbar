<?php

namespace App\Http\Modules\Settlements\Services;

use App\Http\Modules\Settlements\DTOs\SettlementDto;
use App\Http\Modules\Settlements\DTOs\SettlementItemDto;
use App\Http\Modules\Settlements\DTOs\SettlementPreviewDto;
use App\Http\Modules\Settlements\Repositories\SettlementsRepository;
use App\Models\Settlement;
use App\Models\SettlementItem;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Enums\ManualReason;
use App\Shared\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SettlementsService
 *
 * Core service for settlement management (SEPA and manual)
 * Handles settlement creation, preview, export, and cancellation
 *
 * Implements UC-A30 through UC-A35: Settlement operations
 * Implements ADR-0009: Settlement Lead Times
 * Pattern 004: Service Layer
 *
 * Key Business Rules:
 * - Execution date >= Settlement date + 7 days (ADR-0009)
 * - SEPA eligible: iban IS NOT NULL AND mandate_reference IS NOT NULL
 * - Transactions marked as settled (settlement_id set) immediately
 * - Settlements are immutable once exported
 */
final readonly class SettlementsService
{
    /**
     * Initialize service with dependencies
     */
    public function __construct(
        private readonly SettlementsRepository $repository,
        private readonly SepaExportService $sepaExportService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Preview settlement before creation
     *
     * Returns eligible and ineligible members with calculated balances.
     * Used by frontend to show preview before actual settlement creation.
     *
     * @param string|null $fromDate Filter transactions from date (Y-m-d)
     * @param string|null $toDate Filter transactions until date (Y-m-d)
     * @param string|null $memberId Optional: preview single member
     * @param bool $sepaEligibleOnly Optional: show only SEPA-eligible members
     * @return SettlementPreviewDto Preview data with eligible/ineligible members
     */
    public function previewSettlement(
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $memberId = null,
        bool $sepaEligibleOnly = false,
    ): SettlementPreviewDto {
        // Calculate balances for all members
        $balances = $this->repository->calculateMemberBalances($fromDate, $toDate);

        if ($memberId) {
            $balances = array_intersect_key($balances, [$memberId => true]);
        }

        // Fetch member details (IBAN, mandate, status)
        $members = DB::table('members')
            ->whereIn('id', array_keys($balances))
            ->select(['id', 'first_name', 'last_name', 'email', 'iban', 'mandate_reference', 'is_active'])
            ->get();

        // Classify members as SEPA-eligible or not
        $eligible = [];
        $ineligible = [];
        $warnings = [];

        foreach ($members as $member) {
            $balance = $balances[$member->id] ?? 0;
            $memberData = [
                'id' => $member->id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'balance_cents' => $balance,
                'iban' => $member->iban,
            ];

            // Check SEPA eligibility
            if ($member->iban && $member->mandate_reference && $member->is_active) {
                $eligible[] = $memberData;
            } else {
                $ineligible[] = $memberData;

                // Record warnings
                if (!$member->iban) {
                    $warnings[] = "Member {$member->first_name} {$member->last_name}: Missing IBAN";
                }
                if (!$member->mandate_reference) {
                    $warnings[] = "Member {$member->first_name} {$member->last_name}: Missing SEPA mandate";
                }
                if (!$member->is_active) {
                    $warnings[] = "Member {$member->first_name} {$member->last_name}: Account inactive";
                }
            }
        }

        // Calculate totals
        $eligibleTotal = array_sum(array_column($eligible, 'balance_cents'));
        $ineligibleTotal = array_sum(array_column($ineligible, 'balance_cents'));

        // Filter if SEPA-eligible only
        if ($sepaEligibleOnly) {
            $ineligible = [];
        }

        return new SettlementPreviewDto(
            eligibleMembers: $eligible,
            ineligibleMembers: $ineligible,
            eligibleTotal: $eligibleTotal,
            ineligibleTotal: $ineligibleTotal,
            memberCount: count($eligible),
            warnings: $warnings,
        );
    }

    /**
     * Create a settlement
     *
     * Creates settlement record and marks transactions as settled.
     * Generates unique sepa_message_id for settlement export.
     * Logs creation to audit log.
     *
     * @param array $transactionIds Transaction UUIDs to include
     * @param Carbon|string $settlementDate Date settlement was created
     * @param Carbon|string $executionDate Date when payment will be executed
     * @param string|null $periodStart Start of transaction period (optional)
     * @param string|null $periodEnd End of transaction period (optional)
     * @param ManualReason|null $manualReason Optional reason for settlement context
     * @param string|null $notes Admin notes
     * @param string $adminUserId Admin creating the settlement
     * @return SettlementDto The created settlement
     * @throws \Exception If execution_date < settlement_date + 7 days
     */
    public function createSettlement(
        array $transactionIds,
        Carbon|string $settlementDate,
        Carbon|string $executionDate,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        ?ManualReason $manualReason = null,
        ?string $notes = null,
        string $adminUserId = '',
    ): SettlementDto {
        // Ensure dates are Carbon instances
        if (is_string($settlementDate)) {
            $settlementDate = Carbon::parse($settlementDate)->startOfDay();
        }
        if (is_string($executionDate)) {
            $executionDate = Carbon::parse($executionDate)->startOfDay();
        }

        // Validate execution date (ADR-0009: >= settlement_date + 7 days)
        $minimumExecutionDate = $settlementDate->copy()->addDays(7);
        if ($executionDate->isBefore($minimumExecutionDate)) {
            throw new \Exception(
                "Execution date must be at least 7 days after settlement date. " .
                "Minimum: {$minimumExecutionDate->format('Y-m-d')}, given: {$executionDate->format('Y-m-d')}",
            );
        }

        // Fetch transactions to calculate total and member count
        $transactions = DB::table('transactions')
            ->whereIn('id', $transactionIds)
            ->select(['id', 'member_id', 'amount_cents', 'settlement_id'])
            ->get();

        // Validate all transactions are unsettled
        $settledTransactions = $transactions->filter(fn($txn) => $txn->settlement_id !== null);
        if ($settledTransactions->isNotEmpty()) {
            $settledIds = $settledTransactions->pluck('id')->join(', ', ' and ');
            throw new \Exception(
                "Cannot settle already-settled transactions: {$settledIds}"
            );
        }

        // Also validate no settlement_items exist for these transactions (handles orphaned items)
        $existingItems = DB::table('settlement_items')
            ->whereIn('transaction_id', $transactionIds)
            ->pluck('transaction_id');

        if ($existingItems->isNotEmpty()) {
            $existingIds = $existingItems->join(', ', ' and ');
            throw new \Exception(
                "Transactions already have settlement items: {$existingIds}"
            );
        }

        $totalAmount = $transactions->sum('amount_cents');
        $memberIds = $transactions->pluck('member_id')->unique();
        $memberCount = count($memberIds);

        // Generate SEPA message ID for settlement export
        $sepaMessageId = $this->repository->getNextSepaMessageId();

        // Create settlement
        $settlement = $this->repository->create([
            'manual_reason' => $manualReason,
            'settlement_date' => $settlementDate,
            'execution_date' => $executionDate,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'sepa_message_id' => $sepaMessageId,
            'total_amount_cents' => $totalAmount,
            'member_count' => $memberCount,
            'is_cancelled' => false,
            'notes' => $notes,
            'created_by_admin_id' => $adminUserId,
        ]);

        // Create settlement_items (line items)
        foreach ($transactions as $txn) {
            SettlementItem::create([
                'settlement_id' => $settlement->id,
                'transaction_id' => $txn->id,
                'member_id' => $txn->member_id,
                'amount_cents' => $txn->amount_cents,
            ]);
        }

        // Mark transactions as settled
        $this->repository->markTransactionsAsSettled($settlement->id, $transactionIds);

        // Log to audit trail
        $this->auditService->log(
            action: AuditAction::SETTLEMENT_CREATE,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlement->id,
            oldValues: null,
            newValues: [
                'total_amount_cents' => $totalAmount,
                'member_count' => $memberCount,
                'sepa_message_id' => $sepaMessageId,
            ],
            adminUserId: $adminUserId,
        );

        return $this->transformSettlement($settlement);
    }

    /**
     * Get settlement by ID with items
     *
     * @param string $settlementId Settlement UUID
     * @return SettlementDto|null
     */
    public function getSettlement(string $settlementId): ?SettlementDto
    {
        $settlement = $this->repository->findById($settlementId);

        if (!$settlement) {
            return null;
        }

        return $this->transformSettlement($settlement);
    }

    /**
     * List settlements with pagination
     *
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param string|null $type Deprecated: type parameter no longer used (all settlements unified)
     * @return PaginatedResultDto
     */
    public function listSettlements(
        int $page = 1,
        int $perPage = 20,
        ?string $type = null,
        ?string $status = null,
        ?string $sortKey = 'created_at',
        ?string $sortOrder = 'desc'
    ): PaginatedResultDto {
        // Type parameter ignored - all settlements are now unified
        $paginator = $this->repository->findActivePaginated($page, $perPage, $status, $sortKey, $sortOrder);

        $items = collect($paginator->items())->map(fn($s) => $this->transformSettlement($s))->toArray();

        return new PaginatedResultDto(
            items: $items,
            total: $paginator->total(),
            limit: $paginator->perPage(),
            offset: ($page - 1) * $perPage,
        );
    }

    /**
     * Cancel a settlement
     *
     * Unmarks all transactions (sets settlement_id to NULL).
     * Prevents cancellation if settlement has been exported.
     * Logs cancellation to audit trail.
     *
     * @param string $settlementId Settlement UUID
     * @param string $adminUserId Admin cancelling the settlement
     * @param string|null $reason Optional cancellation reason
     * @return bool True if cancelled, false if not found or already exported
     * @throws \Exception If settlement was already exported
     */
    public function cancelSettlement(string $settlementId, string $adminUserId, ?string $reason = null): bool
    {
        $settlement = $this->repository->findById($settlementId);

        if (!$settlement) {
            return false;
        }

        // Prevent cancellation of exported settlements
        if ($settlement->isExported()) {
            throw new \Exception('Cannot cancel settlement that has been exported');
        }

        // Unmark all transactions and delete settlement items
        $items = SettlementItem::where('settlement_id', $settlementId)->get();
        $transactionIds = $items->pluck('transaction_id')->toArray();

        $this->repository->unmarkTransactionsAsSettled($transactionIds);

        // Delete settlement items (cleanup)
        SettlementItem::where('settlement_id', $settlementId)->delete();

        // Mark settlement as cancelled
        $settlement->update([
            'is_cancelled' => true,
            'cancelled_at' => now(),
            'cancelled_by_admin_id' => $adminUserId,
        ]);

        // Log to audit trail
        $this->auditService->log(
            action: AuditAction::SETTLEMENT_CANCEL,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlementId,
            oldValues: ['is_cancelled' => false],
            newValues: ['is_cancelled' => true],
            adminUserId: $adminUserId,
        );

        return true;
    }

    /**
     * Export settlement as SEPA XML
     *
     * Generates SEPA XML file and marks settlement as exported.
     * Only SEPA settlements can be exported.
     *
     * @param string $settlementId Settlement UUID
     * @param string $adminUserId Admin performing export
     * @return string The SEPA XML content
     * @throws \Exception If not a SEPA settlement or SEPA config incomplete
     */
    public function exportSepaXml(string $settlementId, string $adminUserId): string
    {
        $settlement = $this->repository->findById($settlementId);

        if (!$settlement) {
            throw new \Exception('Settlement not found');
        }

        // Load settlement items with member relationships
        $items = SettlementItem::where('settlement_id', $settlementId)
            ->with('member')
            ->get();

        // Generate SEPA XML
        $xml = $this->sepaExportService->generateSepaXml($settlement, $items);

        // Mark as exported
        $settlement->update(['exported_at' => now()]);

        // Log export
        $this->auditService->log(
            action: AuditAction::SETTLEMENT_EXPORT,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlementId,
            oldValues: null,
            newValues: ['format' => 'sepa_xml_pain_008_003_02'],
            adminUserId: $adminUserId,
        );

        return $xml;
    }

    /**
     * Export settlement as CSV
     *
     * Generates CSV file with member details and amounts.
     * Can be used for manual settlement reconciliation.
     *
     * @param string $settlementId Settlement UUID
     * @param string $adminUserId Admin performing export
     * @return string CSV content (semicolon-delimited, UTF-8)
     */
    public function exportCsv(string $settlementId, string $adminUserId): string
    {
        $settlement = $this->repository->findById($settlementId);

        if (!$settlement) {
            throw new \Exception('Settlement not found');
        }

        // Load items with member details
        $items = SettlementItem::where('settlement_id', $settlementId)
            ->with('member')
            ->orderBy('member_id')
            ->get();

        // Group items by member and aggregate amounts
        $memberTotals = [];
        foreach ($items as $item) {
            $memberId = $item->member_id;
            if (!isset($memberTotals[$memberId])) {
                $memberTotals[$memberId] = [
                    'member' => $item->member,
                    'total_cents' => 0,
                ];
            }
            $memberTotals[$memberId]['total_cents'] += $item->amount_cents;
        }

        // Build CSV header
        $csv = "Member Name;Email;IBAN;Amount EUR\n";

        // Output one line per member with aggregated amount
        foreach ($memberTotals as $data) {
            $member = $data['member'];
            $amountEur = number_format($data['total_cents'] / 100, 2, '.', '');

            $csv .= sprintf(
                "\"%s\";%s;%s;%s\n",
                addslashes($member->first_name . ' ' . $member->last_name),
                $member->email,
                $member->iban,
                $amountEur,
            );
        }

        // Log export
        $this->auditService->log(
            action: AuditAction::SETTLEMENT_EXPORT,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlementId,
            oldValues: null,
            newValues: ['format' => 'csv'],
            adminUserId: $adminUserId,
        );

        return $csv;
    }

    /**
     * Export settlement transactions as detailed CSV
     *
     * Generates CSV with one row per transaction showing member details and amounts.
     * Useful for detailed transaction reconciliation and accounting.
     *
     * @param string $settlementId Settlement UUID
     * @param string $adminUserId Admin performing export
     * @return string CSV content (semicolon-delimited, UTF-8)
     */
    public function exportTransactionsCsv(string $settlementId, string $adminUserId): string
    {
        $settlement = $this->repository->findById($settlementId);

        if (!$settlement) {
            throw new \Exception('Settlement not found');
        }

        // Load items with transaction and member details
        $items = SettlementItem::where('settlement_items.settlement_id', $settlementId)
            ->with(['member', 'transaction'])
            ->join('transactions', 'settlement_items.transaction_id', '=', 'transactions.id')
            ->orderBy('settlement_items.member_id')
            ->orderBy('transactions.created_at')
            ->select('settlement_items.*')
            ->get();

        // Build CSV header with detailed transaction info
        $csv = "Transaction ID;Transaction Date;Transaction Type;Product ID;Product;Member Name;Member Email;Member IBAN;Amount EUR;Notes\n";

        // Output one line per transaction
        foreach ($items as $item) {
            $member = $item->member;
            $transaction = $item->transaction;
            $amountEur = number_format($item->amount_cents / 100, 2, '.', '');

            // Get product name if available
            $productName = '';
            if ($transaction->product_id) {
                $product = DB::table('products')
                    ->where('id', $transaction->product_id)
                    ->select('names')
                    ->first();

                if ($product) {
                    $names = json_decode($product->names, true);
                    $productName = reset($names) ?? 'Unknown Product';
                } else {
                    $productName = 'Product (Deleted)';
                }
            }

            // Get transaction type label
            $typeLabel = match($transaction->transaction_type) {
                'purchase' => 'Purchase',
                'correction' => 'Correction',
                default => 'Unknown',
            };

            // Get transaction date
            $txnDate = $transaction->created_at->format('Y-m-d H:i:s');

            // Get notes (correction reason or empty)
            $notes = $transaction->notes ?? '';

            $csv .= sprintf(
                "%s;%s;%s;%s;\"%s\";\"%s\";\"%s\";%s;%s;\"%s\"\n",
                $transaction->id,
                $txnDate,
                $typeLabel,
                $transaction->product_id ?? '',
                addslashes($productName),
                addslashes($member->first_name . ' ' . $member->last_name),
                addslashes($member->email),
                $member->iban ?? '',
                $amountEur,
                addslashes($notes),
            );
        }

        // Log export
        $this->auditService->log(
            action: AuditAction::SETTLEMENT_EXPORT,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlementId,
            oldValues: null,
            newValues: ['format' => 'transactions_csv'],
            adminUserId: $adminUserId,
        );

        return $csv;
    }

    /**
     * Transform Settlement model to SettlementDto
     *
     * @param Settlement $settlement
     * @return SettlementDto
     */
    private function transformSettlement(Settlement $settlement): SettlementDto
    {
        // Load items if not already loaded
        if (!$settlement->relationLoaded('items')) {
            $settlement->load('items.member');
        }

        // Load admin user if not already loaded
        if (!$settlement->relationLoaded('createdBy')) {
            $settlement->load('createdBy');
        }

        // Transform items
        $items = $settlement->items->map(fn($item) => new SettlementItemDto(
            settlementId: $item->settlement_id,
            transactionId: $item->transaction_id,
            memberId: $item->member_id,
            memberName: $item->member->first_name . ' ' . $item->member->last_name,
            amountCents: $item->amount_cents,
        ))->toArray();

        // Build admin name
        $adminName = null;
        if ($settlement->createdBy) {
            $adminName = trim($settlement->createdBy->first_name . ' ' . $settlement->createdBy->last_name);
            if (empty($adminName)) {
                $adminName = $settlement->createdBy->email;
            }
        }

        return new SettlementDto(
            id: $settlement->id,
            manualReason: $settlement->manual_reason?->value,
            settlementDate: $settlement->settlement_date->format('Y-m-d'),
            executionDate: $settlement->execution_date->format('Y-m-d'),
            periodStart: $settlement->period_start?->format('Y-m-d'),
            periodEnd: $settlement->period_end?->format('Y-m-d'),
            sepaMessageId: $settlement->sepa_message_id,
            totalAmountCents: $settlement->total_amount_cents,
            memberCount: $settlement->member_count,
            isCancelled: $settlement->is_cancelled,
            cancelledAt: $settlement->cancelled_at?->toIso8601String(),
            exportedAt: $settlement->exported_at?->toIso8601String(),
            notes: $settlement->notes,
            items: $items,
            createdAt: $settlement->created_at->toIso8601String(),
            createdByAdminId: $settlement->created_by_admin_id,
            createdByAdminName: $adminName,
        );
    }
}
