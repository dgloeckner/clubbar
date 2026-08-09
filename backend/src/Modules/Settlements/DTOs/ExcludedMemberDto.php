<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

use App\Modules\Settlements\Enums\SepaExclusionReason;

/**
 * One member the SEPA export left out of the bank file, and why (#114).
 *
 * Carries the name as well as the id: the treasurer reading the warning has to
 * act on it — restore an IBAN, chase a mandate, pay a credit back — and an
 * exclusion report of bare UUIDs is not actionable.
 */
final readonly class ExcludedMemberDto
{
    public function __construct(
        public string $memberId,
        public ?string $firstName,
        public ?string $lastName,
        /** Signed. Positive is money the file did not collect; negative is a credit. */
        public int $amountCents,
        public SepaExclusionReason $reason,
    ) {}

    /** @param array<string, mixed>|null $member A `members` row, or null when it is gone entirely. */
    public static function fromMember(
        string $memberId,
        ?array $member,
        int $amountCents,
        SepaExclusionReason $reason,
    ): self {
        return new self(
            memberId: $memberId,
            firstName: $member['first_name'] ?? null,
            lastName: $member['last_name'] ?? null,
            amountCents: $amountCents,
            reason: $reason,
        );
    }

    public function displayName(): string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name !== '' ? $name : $this->memberId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'member_id' => $this->memberId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'amount_cents' => $this->amountCents,
            'reason' => $this->reason->value,
        ];
    }
}
