<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\SettlementDto;
use App\DTO\SettlementItemDto;
use App\DTO\SettlementPreviewDto;
use App\DTO\PaginatedResultDto;
use App\Enum\AuditAction;
use App\Enum\EntityType;
use App\Repository\SettlementsRepository;
use App\Repository\MembersRepository;
use App\Repository\TransactionsRepository;
use PDO;

class SettlementsService
{
    public function __construct(
        private SettlementsRepository $settlementsRepository,
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private AuditService $auditService,
        private PDO $db,
    ) {}

    public function previewSettlement(?string $fromDate = null, ?string $toDate = null, ?string $memberId = null, bool $sepaEligibleOnly = false): SettlementPreviewDto
    {
        $balances = $this->settlementsRepository->calculateMemberBalances($fromDate, $toDate);

        if ($memberId) {
            $balances = array_filter($balances, fn($k) => $k === $memberId, ARRAY_FILTER_USE_KEY);
        }

        $memberIds = array_keys($balances);
        if (empty($memberIds)) {
            return new SettlementPreviewDto([], [], 0, 0, 0, []);
        }

        $eligible = [];
        $ineligible = [];
        $warnings = [];

        foreach ($memberIds as $mid) {
            $member = $this->membersRepository->findById($mid);
            if (!$member) continue;

            $entry = [
                'member_id' => $mid,
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'balance_cents' => $balances[$mid],
                'iban' => $member['iban'] ?? null,
                'mandate_reference' => $member['mandate_reference'] ?? null,
            ];

            $isSepaEligible = !empty($member['iban']) && !empty($member['mandate_reference']) && (bool) $member['is_active'];
            if ($isSepaEligible) {
                $eligible[] = $entry;
            } else {
                $ineligible[] = $entry;
                if (!$sepaEligibleOnly) {
                    $warnings[] = "Member {$member['first_name']} {$member['last_name']} is not SEPA-eligible";
                }
            }
        }

        return new SettlementPreviewDto(
            eligibleMembers: $eligible,
            ineligibleMembers: $ineligible,
            eligibleTotal: array_sum(array_column($eligible, 'balance_cents')),
            ineligibleTotal: array_sum(array_column($ineligible, 'balance_cents')),
            memberCount: count($eligible) + count($ineligible),
            warnings: $warnings,
        );
    }

    public function createSettlement(array $transactionIds, string $settlementDate, string $executionDate, ?string $periodStart, ?string $periodEnd, ?string $manualReason, ?string $notes, string $adminUserId): SettlementDto
    {
        $this->db->beginTransaction();
        try {
            // Validate no conflicts
            $conflicts = $this->settlementsRepository->hasConflicts($transactionIds);
            if (!empty($conflicts)) {
                throw new \RuntimeException('Some transactions are already settled');
            }

            // Fetch transactions
            $transactions = $this->transactionsRepository->findUnsettledByIds($transactionIds);
            if (empty($transactions)) {
                throw new \RuntimeException('No valid unsettled transactions found');
            }

            $totalAmount = array_sum(array_column($transactions, 'amount_cents'));
            $memberIds = array_unique(array_column($transactions, 'member_id'));

            $sepaMessageId = $this->settlementsRepository->getNextSepaMessageId();

            $settlement = $this->settlementsRepository->create([
                'manual_reason' => $manualReason,
                'settlement_date' => $settlementDate,
                'execution_date' => $executionDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'sepa_message_id' => $sepaMessageId,
                'total_amount_cents' => $totalAmount,
                'member_count' => count($memberIds),
                'notes' => $notes,
                'created_by_admin_id' => $adminUserId,
            ]);

            foreach ($transactions as $tx) {
                $this->settlementsRepository->createItem([
                    'settlement_id' => $settlement['id'],
                    'transaction_id' => $tx['id'],
                    'member_id' => $tx['member_id'],
                    'amount_cents' => $tx['amount_cents'],
                ]);
            }

            $this->auditService->log(
                action: AuditAction::SETTLEMENT_CREATE,
                entityType: EntityType::SETTLEMENT,
                entityId: $settlement['id'],
                newValues: ['total_amount_cents' => $totalAmount, 'member_count' => count($memberIds), 'transaction_count' => count($transactions)],
                adminUserId: $adminUserId,
            );

            $this->db->commit();

            $items = $this->settlementsRepository->findItemsBySettlementId($settlement['id']);
            $itemDtos = array_map(fn($row) => SettlementItemDto::fromRow($row), $items);

            return SettlementDto::fromRow($settlement, $itemDtos);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getSettlement(string $settlementId): ?SettlementDto
    {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) return null;

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        $itemDtos = array_map(fn($row) => SettlementItemDto::fromRow($row), $items);

        return SettlementDto::fromRow($settlement, $itemDtos);
    }

    public function listSettlements(int $limit, int $offset, ?string $status = null, string $sortKey = 'created_at', string $sortOrder = 'desc'): PaginatedResultDto
    {
        $result = $this->settlementsRepository->listPaginated($limit, $offset, $status, $sortKey, $sortOrder);
        $items = array_map(fn($row) => SettlementDto::fromRow($row)->toArray(), $result['items']);

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function cancelSettlement(string $settlementId, string $adminUserId, ?string $reason = null): bool
    {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) throw new \RuntimeException("Settlement not found: $settlementId");
        if ($settlement['exported_at']) throw new \RuntimeException('Cannot cancel exported settlement');

        $result = $this->settlementsRepository->cancelSettlement($settlementId, $adminUserId);

        $this->auditService->log(
            action: AuditAction::SETTLEMENT_CANCEL,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlementId,
            oldValues: ['is_cancelled' => false],
            newValues: ['is_cancelled' => true, 'reason' => $reason],
            adminUserId: $adminUserId,
        );

        return $result;
    }

    public function markExported(string $settlementId, string $adminUserId): bool
    {
        $result = $this->settlementsRepository->markExported($settlementId);

        $this->auditService->log(
            action: AuditAction::SETTLEMENT_EXPORT,
            entityType: EntityType::SETTLEMENT,
            entityId: $settlementId,
            newValues: ['exported_at' => date('Y-m-d H:i:s')],
            adminUserId: $adminUserId,
        );

        return $result;
    }
}
