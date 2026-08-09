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
 *
 * The name is for the person reading the exclusion *now*. Anything written
 * down — an audit entry, a log line — takes the id instead, because neither
 * can be reached by the erasure that removes the name everywhere else (#115).
 * {@see self::displayName()} against {@see self::toAuditArray()}.
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

    /**
     * The same exclusion as a *record* rather than a report: the member's id,
     * never their name.
     *
     * An export's audit entry is filed under the settlement, so an erasure
     * sweeping the member's own entries cannot reach inside it, and nulling
     * the whole payload would destroy the exclusion record of every other
     * member in the same run. Names in here therefore outlive the erasure that
     * removed them everywhere else (#115). The id does not: once the member
     * row is anonymized it resolves to nobody, while a treasurer reading the
     * entry for a member who still exists can still resolve it to a name.
     *
     * The same reasoning covers the application log, which no erasure reaches
     * at all, so both use this projection — and it is the *only* array
     * projection this DTO offers. The one it replaced serialized the name
     * alongside the id, and it was the nearest thing to hand at both call
     * sites. The name is still here for whoever displays the exclusion
     * ({@see self::displayName()}); it simply has no serializer that a future
     * caller could reach for without meaning to.
     *
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'member_id' => $this->memberId,
            'amount_cents' => $this->amountCents,
            'reason' => $this->reason->value,
        ];
    }
}
