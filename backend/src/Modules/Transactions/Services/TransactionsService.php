<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Services;

use App\Modules\Transactions\DTOs\TransactionBatchResultDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\SepaValidationException;
use App\Shared\Exceptions\ValidationException;
use App\Modules\Members\Repositories\MembersRepository;
use App\Shared\Logging\Logger;
use App\Shared\Utils\DateFormatter;

class TransactionsService
{
    public function __construct(
        private TransactionsRepository $transactionsRepository,
        private MembersRepository $membersRepository,
        private Logger $logger,
    ) {}

    public function processBatch(array $transactions): TransactionBatchResultDto
    {
        $acceptedIds = [];
        $errors = [];
        $affectedMemberIds = [];

        foreach ($transactions as $tx) {
            if (empty($tx['member_id'])) {
                $errors[] = ['id' => $tx['id'] ?? null, 'error' => 'member_id is required'];
                continue;
            }

            $member = $this->membersRepository->findById($tx['member_id']);
            if (!$member) {
                $errors[] = ['error' => 'not_found', 'transaction_id' => $tx['id'], 'message' => 'Member not found'];
                continue;
            }

            // Check SEPA validity: both iban and mandate_reference must be present
            if (empty($member['iban']) || empty($member['mandate_reference'])) {
                $errors[] = [
                    'error' => 'sepa_invalid',
                    'transaction_id' => $tx['id'],
                    'message' => 'SEPA mandate is required to process transactions for this member',
                ];
                continue;
            }

            $result = $this->transactionsRepository->insertTransaction($tx);
            if ($result === null) {
                // Duplicate - treat as accepted (idempotent)
                $acceptedIds[] = $tx['id'];
            } else {
                $acceptedIds[] = $tx['id'];
            }
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
     * Record a storno — the reversal of one specific transaction.
     *
     * The linkage is mandatory: GoBD Rz. 64 requires a reversal to name what it
     * reverses, and the database refuses an unlinked storno outright. Deriving
     * the amount from the original rather than trusting the caller, and lifting
     * the mandate gate below, are the remaining halves of the storno ruling and
     * belong to #169 — this method only stops writing rows the schema rejects.
     */
    public function recordCorrection(
        string $memberId,
        int $amountCents,
        string $reason,
        ?string $adminId = null,
        ?string $relatedTransactionId = null,
    ): array {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        // Check SEPA validity: both iban and mandate_reference must be present
        if (empty($member['iban']) || empty($member['mandate_reference'])) {
            throw new SepaValidationException('SEPA mandate is required to create corrections for this member');
        }

        if ($relatedTransactionId === null) {
            throw new ValidationException(
                'A storno must name the transaction it reverses',
                ['related_transaction_id' => ['related_transaction_id is required']],
            );
        }

        $original = $this->transactionsRepository->findById($relatedTransactionId);
        if (!$original || $original['member_id'] !== $memberId) {
            throw NotFoundException::forResource('Transaction', $relatedTransactionId);
        }

        $id = $this->generateUuid();
        $result = $this->transactionsRepository->insertTransaction([
            'id' => $id,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => $amountCents,
            'transaction_type' => 'storno',
            'notes' => $reason,
            'created_by_admin_id' => $adminId,
            'related_transaction_id' => $relatedTransactionId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $balance = $this->transactionsRepository->getUnsettledMemberBalanceCents($memberId);

        return [
            'transaction' => is_array($result) ? $this->formatTransactionTimestamps($result) : $result,
            'new_balance_cents' => $balance,
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

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
