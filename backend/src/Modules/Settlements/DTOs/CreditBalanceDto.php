<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

/**
 * A member the club currently owes money (#161 work item 3, standing
 * "credit balances outstanding" listing under Members).
 */
final readonly class CreditBalanceDto
{
    public function __construct(
        public string $memberId,
        public string $firstName,
        public string $lastName,
        public int $balanceCents,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            memberId: $row['member_id'],
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            balanceCents: (int) $row['balance_cents'],
        );
    }

    public function toArray(): array
    {
        return [
            'member_id' => $this->memberId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'balance_cents' => $this->balanceCents,
        ];
    }
}
