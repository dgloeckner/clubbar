<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\DTOs\CreditBalanceDto;
use App\Modules\Settlements\DTOs\ExecutionDateInfoDto;
use App\Modules\Settlements\DTOs\SettlementDto;
use App\Modules\Settlements\DTOs\SettlementItemDto;
use App\Modules\Settlements\DTOs\SettlementPreviewDto;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\ValidationException;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Services\AuditService;
use App\Shared\Utils\BankingCalendar;
use PDO;

class SettlementsService
{
    /** Fixed SEPA lead time in calendar days (ADR-0009). */
    public const LEAD_TIME_DAYS = 7;

    public function __construct(
        private SettlementsRepository $settlementsRepository,
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private AuditService $auditService,
        private PDO $db,
    ) {}

    /**
     * The earliest execution date an admin may choose (ADR-0009).
     *
     * TODAY + 7 calendar days, rolled forward to the next TARGET2 business day
     * so the resulting ReqdColltnDt is always a settlement day. Around Easter
     * the roll can add four days, since Good Friday through Easter Monday are
     * four consecutive closing days.
     *
     * @param string|null $today Injectable for tests; defaults to the current date.
     */
    public function getExecutionDateInfo(?string $today = null): ExecutionDateInfoDto
    {
        $base = (new \DateTimeImmutable($today ?? 'today'))->modify('+7 days');

        return new ExecutionDateInfoDto(
            minimumDate: BankingCalendar::nextBusinessDay($base->format('Y-m-d')),
            leadTimeDays: self::LEAD_TIME_DAYS,
            rule: 'execution_date >= today + 7 calendar days, rolled to the next bank business day '
                . '(Mon-Fri, excluding TARGET2 closing days)',
        );
    }

    /**
     * What a collection run would contain, and who it would leave out and why
     * (ruling #141, issue #161).
     *
     * The date window selects the run's *participants* — the members with
     * unsettled activity in it. It does not bound the amounts: each
     * participant's whole unsettled position is what gets tested and, on
     * inclusion, what gets swept.
     */
    public function previewSettlement(?string $fromDate = null, ?string $toDate = null, ?string $memberId = null, bool $sepaEligibleOnly = false): SettlementPreviewDto
    {
        $memberIds = $this->settlementsRepository->findParticipantMemberIds($fromDate, $toDate, $memberId);
        if (empty($memberIds)) {
            return new SettlementPreviewDto([], [], 0, 0, 0, []);
        }

        $positions = $this->settlementsRepository->calculateUnsettledPositions($memberIds);

        $eligible = [];
        $ineligible = [];
        $credit = [];
        $warnings = [];

        foreach ($memberIds as $mid) {
            $member = $this->membersRepository->findById($mid);
            if (!$member) continue;

            $balance = $positions[$mid] ?? 0;
            $entry = [
                'member_id' => $mid,
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'balance_cents' => $balance,
                'iban' => $member['iban'] ?? null,
                'mandate_reference' => $member['mandate_reference'] ?? null,
            ];

            // Credit is tested first and reported even under sepa_eligible_only:
            // the club owes this member money (§ 812 BGB), so "chase the bank
            // details" is not the remedy and the exclusion must never be silent.
            if ($balance < 0) {
                $credit[] = $entry;
                $warnings[] = "Member {$member['first_name']} {$member['last_name']} is in credit and is excluded from collection";
                continue;
            }

            if ($this->hasActiveMandate($member)) {
                // Zero settles too — it closes the rows out. Only the export
                // decides not to write a file line for it.
                $eligible[] = $entry;
            } elseif (!$sepaEligibleOnly) {
                $ineligible[] = $entry;
                $warnings[] = "Member {$member['first_name']} {$member['last_name']} has no active SEPA mandate";
            }
        }

        return new SettlementPreviewDto(
            eligibleMembers: $eligible,
            ineligibleMembers: $ineligible,
            eligibleTotal: array_sum(array_column($eligible, 'balance_cents')),
            ineligibleTotal: array_sum(array_column($ineligible, 'balance_cents')),
            memberCount: count($eligible) + count($ineligible) + count($credit),
            warnings: $warnings,
            creditMembers: $credit,
            creditTotal: array_sum(array_column($credit, 'balance_cents')),
        );
    }

    /**
     * SEPA validity is one question — does the member hold an active mandate
     * (#164) — asked of the mandate record the member row joins in, not a
     * conjunction of loosely-maintained columns. `is_active` is deliberately
     * not part of it: deactivation is temporary (a lost card), and must not
     * strand debt the member genuinely owes (ruling #173).
     */
    private function hasActiveMandate(array $member): bool
    {
        return !empty($member['mandate_reference']) && !empty($member['iban']);
    }

    /**
     * Return lightweight aggregate preview for all unsettled transactions
     * matching the given journal filters.
     *
     * @param array{ date_from?: string, date_to?: string, search?: string, member_id?: string } $filters
     * @return array{ transaction_count: int, member_count: int, total_amount_cents: int }
     */
    public function previewByFilters(array $filters): array
    {
        return $this->transactionsRepository->summarizeUnsettledByFilters($filters);
    }

    public function createSettlement(array $transactionIds, string $settlementDate, string $executionDate, ?string $periodStart, ?string $periodEnd, SettlementMethod $method, ?string $notes, string $adminUserId): SettlementDto
    {
        $this->db->beginTransaction();
        try {
            // Validate no conflicts
            $conflicts = $this->settlementsRepository->hasConflicts($transactionIds);
            if (!empty($conflicts)) {
                throw new BusinessRuleException('Some transactions are already settled');
            }

            // Fetch transactions
            $posted = $this->transactionsRepository->findUnsettledByIds($transactionIds);
            if (empty($posted)) {
                throw new BusinessRuleException('No valid unsettled transactions found');
            }

            // The posted ids say *who* is being settled; the run then covers
            // each of those members in full (#161 §2).
            $memberIds = array_values(array_unique(array_column($posted, 'member_id')));

            // #161 §6: the preview's verdict is binding. Posting ids the
            // preview flagged used to settle them regardless, because creation
            // validated nothing at all.
            $partition = $this->partitionByCollectability($memberIds, $method);
            if (!empty($partition['ineligible']) || !empty($partition['credit'])) {
                $messages = [];
                if (!empty($partition['ineligible'])) {
                    $messages['ineligible_member_ids'] =
                        ['These members have no active SEPA mandate: ' . implode(', ', $partition['ineligible'])];
                }
                if (!empty($partition['credit'])) {
                    $messages['credit_member_ids'] =
                        ['These members are in credit and are excluded from collection: ' . implode(', ', $partition['credit'])];
                }

                throw new ValidationException(
                    'Some members cannot be settled — ' . implode('; ', array_map(fn(array $m) => $m[0], $messages)),
                    $messages,
                );
            }

            $transactions = $this->transactionsRepository->findUnsettledByMemberIds($memberIds);
            $totalAmount = array_sum(array_column($transactions, 'amount_cents'));

            // Ruling #163: bank_transfer/write_off settlements cover exactly one
            // member (also enforced in the DB by chk_settlements_manual_is_single_member).
            // Reject here so the caller gets a 422 instead of a raw SQL CHECK violation.
            if (!$method->isSepaExportable() && count($memberIds) !== 1) {
                throw new ValidationException(
                    'Non-direct-debit settlements must cover exactly one member',
                    ['method' => ['bank_transfer and write_off settlements must cover exactly one member']],
                );
            }

            $sepaMessageId = $this->settlementsRepository->getNextSepaMessageId();

            $settlement = $this->settlementsRepository->create([
                'method' => $method->value,
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

    /**
     * Split a run's participants into those it can collect from and those it
     * must leave out, with the reason kept distinct (ruling #141, #161 §3).
     *
     * @param list<string> $memberIds
     * @return array{collectable: list<string>, ineligible: list<string>, credit: list<string>}
     */
    private function partitionByCollectability(array $memberIds, SettlementMethod $method): array
    {
        $positions = $this->settlementsRepository->calculateUnsettledPositions($memberIds);

        $collectable = [];
        $ineligible = [];
        $credit = [];

        foreach ($memberIds as $memberId) {
            // A negative position means the club owes this member money, so no
            // settlement may touch them whatever their bank details say.
            if (($positions[$memberId] ?? 0) < 0) {
                $credit[] = $memberId;
                continue;
            }

            $member = $this->membersRepository->findById($memberId);

            // Only a direct debit needs a mandate: a bank transfer is the
            // member paying their own tab, and a write-off collects nothing.
            if ($method->isSepaExportable() && ($member === null || !$this->hasActiveMandate($member))) {
                $ineligible[] = $memberId;
                continue;
            }

            $collectable[] = $memberId;
        }

        return ['collectable' => $collectable, 'ineligible' => $ineligible, 'credit' => $credit];
    }

    /**
     * Create a settlement for all unsettled transactions matching the given filters.
     *
     * Unlike createSettlement(), which is handed a list the admin picked and
     * refuses it if anything in it cannot be collected, this path is a sweep:
     * members it cannot collect from are excluded and the run proceeds
     * (exclude-and-flag, ruling #141).
     *
     * @param array{ date_from?: string, date_to?: string, search?: string, member_id?: string } $filters
     */
    public function createSettlementByFilters(
        array $filters,
        string $settlementDate,
        string $executionDate,
        string $adminUserId,
        ?string $notes = null,
        SettlementMethod $method = SettlementMethod::DIRECT_DEBIT,
    ): SettlementDto {
        $transactionIds = $this->transactionsRepository->findAllUnsettledByFilters($filters);
        if (empty($transactionIds)) {
            throw new BusinessRuleException('No unsettled transactions found for the given filters');
        }

        $matched = $this->transactionsRepository->findUnsettledByIds($transactionIds);
        $memberIds = array_values(array_unique(array_column($matched, 'member_id')));
        $collectable = $this->partitionByCollectability($memberIds, $method)['collectable'];
        if (empty($collectable)) {
            throw new BusinessRuleException(
                'No collectable members matched the given filters — every match is in credit or has no active SEPA mandate'
            );
        }

        $collectableIds = [];
        foreach ($matched as $transaction) {
            if (in_array($transaction['member_id'], $collectable, true)) {
                $collectableIds[] = $transaction['id'];
            }
        }

        return $this->createSettlement(
            transactionIds: $collectableIds,
            settlementDate: $settlementDate,
            executionDate: $executionDate,
            periodStart: $filters['date_from'] ?? null,
            periodEnd: $filters['date_to'] ?? null,
            method: $method,
            notes: $notes,
            adminUserId: $adminUserId,
        );
    }

    public function getSettlement(string $settlementId): ?SettlementDto
    {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) return null;

        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        $itemDtos = array_map(fn($row) => SettlementItemDto::fromRow($row), $items);

        return SettlementDto::fromRow($settlement, $itemDtos);
    }

    public function listSettlements(int $limit, int $offset, ?string $status = null, string $sortKey = 'created_at', string $sortOrder = 'desc', ?string $dateFrom = null, ?string $dateTo = null): PaginatedResultDto
    {
        $result = $this->settlementsRepository->listPaginated($limit, $offset, $status, $sortKey, $sortOrder, $dateFrom, $dateTo);
        $items = array_map(fn($row) => SettlementDto::fromRow($row)->toArray(), $result['items']);

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function cancelSettlement(string $settlementId, string $adminUserId, ?string $reason = null): bool
    {
        $settlement = $this->settlementsRepository->findById($settlementId);
        if (!$settlement) throw NotFoundException::forResource('Settlement', $settlementId);

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

    public function getCsvData(string $settlementId): array
    {
        $items = $this->settlementsRepository->findItemsBySettlementId($settlementId);
        $memberData = [];
        foreach ($items as $item) {
            $mid = $item['member_id'];
            if (!isset($memberData[$mid])) {
                $member = $this->membersRepository->findByIdIncludingDeleted($mid);
                $memberData[$mid] = [
                    'name' => trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? '')),
                    'email' => $member['email'] ?? '',
                    'iban' => $member['iban'] ?? '',
                    'amount_cents' => 0,
                ];
            }
            $memberData[$mid]['amount_cents'] += (int) $item['amount_cents'];
        }
        return array_values($memberData);
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

    /**
     * Standing "credit balances outstanding" listing under Members (#161 work
     * item 3, non-blocking) — every member the club currently owes money,
     * most-negative first, with the sum of what is owed.
     *
     * @return array{items: list<CreditBalanceDto>, total_credit_cents: int}
     */
    public function listCreditBalances(): array
    {
        $rows = $this->settlementsRepository->findMembersInCredit();
        $items = array_map(fn(array $row) => CreditBalanceDto::fromRow($row), $rows);

        return [
            'items' => $items,
            'total_credit_cents' => array_sum(array_column($rows, 'balance_cents')),
        ];
    }
}
