<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Services;

use App\Shared\Utils\Uuid;
use App\Modules\Transactions\DTOs\TransactionBatchResultDto;
use App\Modules\Transactions\Exceptions\CannotStornoAStornoException;
use App\Modules\Transactions\Exceptions\TransactionAlreadyStornoedException;
use App\Modules\Transactions\Exceptions\TransactionNotStorableException;
use App\Shared\DTOs\PaginatedResultDto;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Modules\Transactions\Sync\TerminalTransactionValidator;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Modules\Members\Repositories\MembersRepository;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use App\Shared\Utils\DateFormatter;

class TransactionsService
{
    public function __construct(
        private TransactionsRepository $transactionsRepository,
        private MembersRepository $membersRepository,
        private AuditService $auditService,
        private Logger $logger,
    ) {}

    /**
     * @param list<string> $requestedMemberIds Members whose balance the caller
     *        wants reported even though this batch does not touch them (#191).
     *        A terminal after a settlement has nothing to upload, so without
     *        this it could never learn that the tab is now zero — it would keep
     *        showing the pre-settlement Deckel until the member's next purchase.
     */
    public function processBatch(array $transactions, array $requestedMemberIds = []): TransactionBatchResultDto
    {
        $acceptedIds = [];
        $errors = [];
        $affectedMemberIds = [];

        foreach ($requestedMemberIds as $memberId) {
            // Unknown ids are skipped, not reported as 0. A phantom zero would
            // read to the terminal as "this member owes nothing" and overwrite a
            // real cached balance; an absent key leaves the cache alone, which
            // is the same graceful degradation an offline scan already gets.
            if ($this->membersRepository->findById($memberId)) {
                $affectedMemberIds[$memberId] = true;
            }
        }

        foreach ($transactions as $tx) {
            // #259, ruling #143 §2: judged per row, not per batch. This used to
            // be a batch-wide 422 in SyncController — one unstorable row and
            // nothing at all was written, which the terminal then retried
            // unchanged forever, so every good sale behind it went uncollected.
            $rejection = TerminalTransactionValidator::rejectionFor($tx);
            if ($rejection !== null) {
                $errors[] = $rejection;
                continue;
            }

            $member = $this->membersRepository->findById($tx['member_id']);
            if (!$member) {
                $errors[] = ['error' => 'not_found', 'transaction_id' => $tx['id'], 'message' => 'Member not found'];
                continue;
            }

            // #162, ruling #143 §1: a missing mandate is NOT a rejection. The
            // drink was served against the terminal's last synced state; by the
            // time the batch arrives the sale is a historical fact and the only
            // question is whether the row can be stored. Refusing it here would
            // destroy the record of a sale that happened, bill nobody, and show
            // the loss on no report. ADR-0020 keeps the preventive half at the
            // terminal (it blocks at card scan); the server is the backstop and
            // stores-and-flags. The flag is derived, not stored: the member now
            // carries an unsettled balance without an active mandate, which is
            // exactly what puts them in the settlement preview's
            // `ineligible_members` bucket (#161 §3) for the treasurer.
            if (!$this->hasActiveMandate($member)) {
                $this->logger->warning('Transaction stored for member without an active SEPA mandate', [
                    'member_id' => $tx['member_id'],
                    'transaction_id' => $tx['id'] ?? null,
                ]);
            }

            $this->flagImplausibleSaleTime($tx);

            try {
                // A null return means the id was already stored — the terminal
                // is replaying the batch, which ADR-0004 makes safe. Either way
                // the transaction is on the server, so either way it is accepted.
                $this->transactionsRepository->insertTransaction($tx);
            } catch (TransactionNotStorableException $e) {
                // #82: a row the database refused. Never report it as accepted —
                // the terminal would purge a served drink from its offline queue
                // and the sale would exist nowhere. Only this row is rejected;
                // the rest of the batch still lands. Transient failures are not
                // caught here: they propagate so the terminal retries the batch.
                $errors[] = [
                    'error' => 'unstorable',
                    'transaction_id' => $tx['id'] ?? null,
                    'message' => 'Transaction could not be stored and was not accepted',
                ];
                continue;
            }

            $acceptedIds[] = $tx['id'];
            $affectedMemberIds[$tx['member_id']] = true;
        }

        $memberBalances = [];
        foreach (array_keys($affectedMemberIds) as $memberId) {
            $memberBalances[$memberId] = $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);
        }

        return new TransactionBatchResultDto(
            acceptedIds: $acceptedIds,
            rejectedCount: count($errors),
            errors: $errors,
            memberBalances: $memberBalances,
        );
    }

    /**
     * Storno a transaction — reverse one specific booking, in full.
     *
     * The transaction is the subject of the operation, not a parameter of it
     * (UC-A23, #169). Everything the storno needs is read from the target:
     *
     * - the **amount** is the exact negation of the original, and cannot be
     *   supplied by the caller. This is what deletes the sign-convention and
     *   zero-amount error classes rather than validating them away;
     * - the **member** comes from the original too, so a storno can never be
     *   booked against somebody else's tab.
     *
     * There is no SEPA mandate gate. A storno *reduces* what the member owes,
     * and gating a debt reduction on the ability to collect is inverted — it
     * blocked the § 812 BGB remedy for exactly the members who could not be
     * billed (#158 §1, ADR-0028 §1).
     *
     * @throws NotFoundException when no such transaction exists
     * @throws CannotStornoAStornoException when the target is itself a storno
     * @throws TransactionAlreadyStornoedException when the target already has a storno
     */
    public function storno(string $transactionId, string $reason, ?string $adminId = null): array
    {
        $original = $this->transactionsRepository->findById($transactionId);
        if (!$original) {
            throw NotFoundException::forResource('Transaction', $transactionId);
        }

        if (($original['transaction_type'] ?? null) === 'storno') {
            throw new CannotStornoAStornoException(
                'A storno cannot itself be stornoed. Book a new purchase instead.',
            );
        }

        // A courtesy check so the common case reports cleanly. It is not the
        // guarantee — the unique index on related_transaction_id is, and
        // insertStorno() surfaces its refusal (see there).
        if ($this->transactionsRepository->findStornoFor($transactionId) !== null) {
            throw new TransactionAlreadyStornoedException('This transaction has already been stornoed');
        }

        $memberId = $original['member_id'];
        $stored = $this->transactionsRepository->insertStorno([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => -((int) $original['amount_cents']),
            'transaction_type' => 'storno',
            'notes' => $reason,
            'created_by_admin_id' => $adminId,
            'related_transaction_id' => $transactionId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditService->log(
            action: AuditAction::TRANSACTION_STORNO,
            entityType: EntityType::TRANSACTION,
            entityId: $stored['id'],
            newValues: [
                'related_transaction_id' => $transactionId,
                'member_id' => $memberId,
                'amount_cents' => (int) $stored['amount_cents'],
                'reason' => $reason,
            ],
            adminUserId: $adminId,
        );

        return [
            'transaction' => $this->formatTransactionTimestamps($stored),
            'new_balance_cents' => $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId),
        ];
    }

    public function getTransactions(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc'): PaginatedResultDto
    {
        $result = $this->transactionsRepository->listPaginated($limit, $offset, $filters, $sortKey, $sortOrder);

        $items = array_map(fn(array $row) => $this->formatTransactionTimestamps($row), $result['items']);

        return new PaginatedResultDto(
            items: $items,
            total: $result['total'],
            limit: $limit,
            offset: $offset,
        );
    }

    public function getMemberTransactionHistory(string $memberId, ?string $type = null): array
    {
        $balance = $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);
        $transactions = $this->transactionsRepository->findByMemberId($memberId, 1000, 0, $type);
        $transactions = array_map(fn(array $row) => $this->formatTransactionTimestamps($row), $transactions);

        return [
            'member_id' => $memberId,
            'current_balance_cents' => $balance,
            'transactions' => $transactions,
        ];
    }

    public function getRecentTransactions(string $memberId, int $limit = 50, int $offset = 0, ?string $since = null): array
    {
        return $this->transactionsRepository->findByMemberId($memberId, $limit, $offset, null, $since);
    }

    /**
     * Fetch recent transactions for a member with member existence check.
     * Adds translated product_name and normalized type field.
     *
     * @throws NotFoundException when member does not exist
     */
    public function getRecentTransactionsForMember(string $memberId, int $limit = 50, int $offset = 0, ?string $since = null): array
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        $language = $member['preferred_language'] ?? 'de';
        $rows = $this->transactionsRepository->findByMemberId($memberId, $limit, $offset, null, $since);

        foreach ($rows as &$row) {
            // Format timestamps to ISO 8601 UTC
            $row = $this->formatTransactionTimestamps($row);

            // Normalize type field
            $row['type'] = $row['transaction_type'] ?? null;

            // Translate product_name from JSON names column
            $productNames = $row['product_names'] ?? null;
            if ($productNames !== null) {
                $names = is_array($productNames) ? $productNames : (json_decode($productNames, true) ?? []);
                $row['product_name'] = $names[$language] ?? $names['de'] ?? $names['en'] ?? reset($names) ?: null;
            } else {
                // No product (a storno or a payout): use notes, fallback to type label
                $typeLabel = match ($row['transaction_type'] ?? '') {
                    'storno' => 'Storno',
                    'payout' => 'Payout',
                    default  => 'Transaction',
                };
                $row['product_name'] = $row['notes'] ?: $typeLabel;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Format DATETIME fields on a raw transaction row to ISO 8601 UTC.
     * Leaves DATE-only fields (settlement_date) unchanged.
     */
    private function formatTransactionTimestamps(array $row): array
    {
        if (isset($row['created_at'])) {
            $row['created_at'] = DateFormatter::toUtcIso($row['created_at']);
        }
        if (isset($row['updated_at'])) {
            $row['updated_at'] = DateFormatter::toUtcIso($row['updated_at']);
        }
        // settlement_date is DATE-only — no timezone conversion needed
        return $row;
    }

    /**
     * Mirrors SettlementsService: SEPA validity is the single question "does
     * this member hold an active mandate" (#164). Kept identical so the sync
     * flag and the settlement-preview `ineligible_members` bucket never
     * disagree about who needs the treasurer's attention.
     */
    /**
     * How far ahead of the server's clock a sale time may sit before it stops
     * being terminal clock skew and starts being a claim about the future.
     */
    private const FUTURE_SALE_TOLERANCE_SECONDS = 3600;

    /**
     * How far back a sale time may sit before it stops being an offline stretch
     * (ADR-0012 permits days) and starts being a dead clock battery or a
     * backdating attempt. Generous on purpose: the flag must stay rare enough
     * to be worth reading.
     */
    private const STALE_SALE_TOLERANCE_SECONDS = 90 * 86400;

    /**
     * Flag a sale time that cannot be true, and store it anyway (#79, ruling
     * #144 §2).
     *
     * The value is never rewritten and never rejected. Clamping would erase the
     * evidence — a terminal with a flat clock battery and an attacker probing
     * the ledger look identical once the value is corrected in place — and
     * silently editing a recorded fact sits badly with ADR-0004's append-only
     * ledger. Rejecting would refuse an evening's real revenue over a hardware
     * fault, which is exactly what ruling #143 forbids. `received_at`, stamped
     * by the database, is the anchor that makes the claim checkable later.
     *
     * The backdating attack this originally guarded is separately defused:
     * settlement sweeps a member's entire unsettled position regardless of date
     * (#141), so a backdated sale is still collected.
     */
    private function flagImplausibleSaleTime(array $tx): void
    {
        $occurredAt = $tx['created_at'] ?? null;
        if (!is_string($occurredAt) || $occurredAt === '') {
            return;
        }

        try {
            $soldAt = new \DateTimeImmutable($occurredAt);
        } catch (\Exception) {
            // Nothing to judge. The database refuses the row and #82 reports it
            // as unstorable — a rejection, not a flag.
            return;
        }

        $skewSeconds = $soldAt->getTimestamp() - time();

        $direction = match (true) {
            $skewSeconds > self::FUTURE_SALE_TOLERANCE_SECONDS => 'future',
            -$skewSeconds > self::STALE_SALE_TOLERANCE_SECONDS => 'stale',
            default => null,
        };

        if ($direction === null) {
            return;
        }

        $this->logger->warning('Transaction stored with an implausible sale time', [
            'transaction_id' => $tx['id'] ?? null,
            'member_id' => $tx['member_id'] ?? null,
            'terminal_id' => $tx['created_by_terminal_id'] ?? null,
            'occurred_at' => $occurredAt,
            'skew_seconds' => $skewSeconds,
            'direction' => $direction,
        ]);
    }

    private function hasActiveMandate(array $member): bool
    {
        return !empty($member['mandate_reference']) && !empty($member['iban']);
    }
}
