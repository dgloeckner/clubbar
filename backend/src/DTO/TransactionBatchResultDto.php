<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class TransactionBatchResultDto
{
    public function __construct(
        public array $acceptedIds,
        public int $rejectedCount,
        public array $errors,
        public array $memberBalances,
    ) {}

    public function toArray(): array
    {
        return [
            'accepted_ids' => $this->acceptedIds,
            'rejected_count' => $this->rejectedCount,
            'errors' => $this->errors,
            'member_balances' => $this->memberBalances,
        ];
    }
}
