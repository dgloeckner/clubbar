<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\TransactionBatchResultDto;
use App\DTO\PaginatedResultDto;
use App\Repository\TransactionsRepository;
use App\Repository\MembersRepository;
use App\Logging\Logger;

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
            $member = $this->membersRepository->findById($tx['member_id']);
            if (!$member) {
                $errors[] = ['id' => $tx['id'], 'error' => 'Member not found'];
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
            $memberBalances[$memberId] = $this->transactionsRepository->getMemberBalance($memberId);
        }

        return new TransactionBatchResultDto(
            acceptedIds: $acceptedIds,
            rejectedCount: count($errors),
            errors: $errors,
            memberBalances: $memberBalances,
        );
    }

    public function recordCorrection(string $memberId, int $amountCents, string $reason, ?string $adminId = null): array
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw new \RuntimeException("Member not found: $memberId");
        }

        $id = $this->generateUuid();
        $result = $this->transactionsRepository->insertTransaction([
            'id' => $id,
            'member_id' => $memberId,
            'product_id' => null,
            'amount_cents' => $amountCents,
            'transaction_type' => 'correction',
            'notes' => $reason,
            'created_by_admin_id' => $adminId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $balance = $this->transactionsRepository->getMemberBalance($memberId);

        return [
            'transaction' => $result,
            'new_balance_cents' => $balance,
        ];
    }

    public function getTransactions(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc'): PaginatedResultDto
    {
        $result = $this->transactionsRepository->listPaginated($limit, $offset, $filters, $sortKey, $sortOrder);

        return new PaginatedResultDto(
            items: $result['items'],
            total: $result['total'],
            limit: $limit,
            offset: $offset,
        );
    }

    public function getMemberTransactionHistory(string $memberId, ?string $type = null): array
    {
        $balance = $this->transactionsRepository->getMemberBalance($memberId);
        $transactions = $this->transactionsRepository->findByMemberId($memberId, 1000, 0, $type);

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

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
