<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

use App\Modules\Settlements\Enums\ReversalReason;
use App\Shared\Utils\DateFormatter;

/**
 * One member's collection undone after the money moved (ruling #148).
 *
 * Append-only: there is no update and no delete. A reversal recorded in error
 * is not edited away — the settlement it names still says what it originally
 * contained, which is the whole point of retaining the items.
 */
final readonly class SettlementReversalDto
{
    public function __construct(
        public string $id,
        public string $settlementId,
        public string $memberId,
        public ReversalReason $reason,
        public int $amountCents,
        public ?string $bankReference,
        public ?string $notes,
        public ?string $createdByAdminId,
        public string $createdAt,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $createdByAdminName = null,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            settlementId: $row['settlement_id'],
            memberId: $row['member_id'],
            reason: ReversalReason::from($row['reason']),
            amountCents: (int) $row['amount_cents'],
            bankReference: $row['bank_reference'] ?? null,
            notes: $row['notes'] ?? null,
            createdByAdminId: $row['created_by_admin_id'] ?? null,
            createdAt: $row['created_at'],
            firstName: $row['first_name'] ?? null,
            lastName: $row['last_name'] ?? null,
            createdByAdminName: $row['admin_display_name'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'settlement_id' => $this->settlementId,
            'member_id' => $this->memberId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'amount_cents' => $this->amountCents,
            'amount_eur' => round($this->amountCents / 100, 2),
            'bank_reference' => $this->bankReference,
            'notes' => $this->notes,
            'created_by_admin_id' => $this->createdByAdminId,
            'created_by_admin_name' => $this->createdByAdminName,
            'created_at' => DateFormatter::toUtcIso($this->createdAt),
        ];
    }
}
