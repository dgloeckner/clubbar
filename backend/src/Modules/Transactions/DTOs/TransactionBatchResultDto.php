<?php

declare(strict_types=1);

namespace App\Modules\Transactions\DTOs;

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
            'rejected' => [
                'count' => $this->rejectedCount,
                'errors' => $this->errors,
            ],
            'member_balances' => $this->memberBalances ?: new \stdClass(),
        ];
    }
}
