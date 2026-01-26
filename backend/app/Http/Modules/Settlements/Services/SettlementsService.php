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
use App\Shared\Enums\SettlementType;
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
     * Generates unique sepa_message_id for SEPA settlements.
     * Logs creation to audit log.
     *
     * @param SettlementType $settlementType Type of settlement (sepa or manual)
     * @param array $transactionIds Transaction UUIDs to include
     * @param Carbon|string $settlementDate Date settlement was created
     * @param Carbon|string $executionDate Date when payment will be executed
     * @param string|null $periodStart Start of transaction period (optional)
     * @param string|null $periodEnd End of transaction period (optional)
     * @param ManualReason|null $manualReason Reason (required for manual settlements)
     * @param string|null $notes Admin notes
     * @param string $adminUserId Admin creating the settlement
     * @return SettlementDto The created settlement
     * @throws \Exception If execution_date < settlement_date + 7 days
     */
    public function createSettlement(
        SettlementType $settlementType,
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
            ->select(['id', 'member_id', 'amount_cents'])
            ->get();

        $totalAmount = $transactions->sum('amount_cents');
        $memberIds = $transactions->pluck('member_id')->unique();
        $memberCount = count($memberIds);

        // Generate SEPA message ID for SEPA settlements
        $sepaMessageId = null;
        if ($settlementType === SettlementType::SEPA) {
            $sepaMessageId = $this->repository->getNextSepaMessageId();
        }

        // Create settlement
        $settlement = $this->repository->create([
            'settlement_type' => $settlementType,
            'manual_reason' => $manualReason,
            'settlement_date' => $settlementDate,
            'execution_date' => $executionDate,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'sepa_message_id' => $sepaMessageId,
            'total_amount_cents' => $totalAmount,
            'member_count' => $memberCount,
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
                'settlement_type' => $settlementType->value,
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
     * List settlements with pagination and filtering
     *
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param string|null $type Filter by type (sepa or manual)
     * @return PaginatedResultDto
     */
    public function listSettlements(int $page = 1, int $perPage = 20, ?string $type = null): PaginatedResultDto
    {
        if ($type) {
            $paginator = $this->repository->findByTypePaginated($type, $page, $perPage);
        } else {
            $paginator = $this->repository->findActivePaginated($page, $perPage);
        }

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

        // Unmark all transactions
        $items = SettlementItem::where('settlement_id', $settlementId)->get();
        $transactionIds = $items->pluck('transaction_id')->toArray();

        $this->repository->unmarkTransactionsAsSettled($transactionIds);

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

        if (!$settlement->isSepa()) {
            throw new \Exception('Only SEPA settlements can be exported as XML');
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

        // Build CSV
        $csv = "Member Name;Email;IBAN;Amount EUR\n";

        foreach ($items as $item) {
            $member = $item->member;
            $amountEur = number_format($item->amount_cents / 100, 2, '.', '');

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

        // Transform items
        $items = $settlement->items->map(fn($item) => new SettlementItemDto(
            settlementId: $item->settlement_id,
            transactionId: $item->transaction_id,
            memberId: $item->member_id,
            memberName: $item->member->first_name . ' ' . $item->member->last_name,
            amountCents: $item->amount_cents,
        ))->toArray();

        return new SettlementDto(
            id: $settlement->id,
            settlementType: $settlement->settlement_type->value,
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
        );
    }
}
