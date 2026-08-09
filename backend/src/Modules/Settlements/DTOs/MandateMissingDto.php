<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

/**
 * A member the next collection run cannot reach for want of a usable mandate
 * (#258) — the third standing exclusion, beside credit balances and holds.
 *
 * Deliberately no "which half is missing" field, unlike the settlement
 * preview's ineligible bucket. Since #164 the IBAN and the mandate reference
 * live on the same `mandates` row and arrive together, so a member either has
 * both or neither: a column reporting the difference would say "missing both"
 * forever. The section says what is missing in its own note instead.
 */
final readonly class MandateMissingDto
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
