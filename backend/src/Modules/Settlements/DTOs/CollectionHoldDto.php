<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

use App\Shared\Utils\DateFormatter;

/**
 * A member the next collection run must leave alone (ruling #148 §3).
 *
 * The hold carries its own reason because the whole purpose of the bucket is
 * that the exclusion is never silent: the treasurer has to be able to see
 * *why* this member was skipped and decide whether to clear it.
 */
final readonly class CollectionHoldDto
{
    public function __construct(
        public string $memberId,
        public string $firstName,
        public string $lastName,
        public ?string $reason,
        public ?string $heldAt,
        public ?string $heldByAdminId,
        public ?string $heldByAdminName = null,
        public int $balanceCents = 0,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            memberId: $row['member_id'] ?? $row['id'],
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            reason: $row['collection_hold_reason'] ?? null,
            heldAt: $row['held_at'] ?? null,
            heldByAdminId: $row['held_by_admin_id'] ?? null,
            heldByAdminName: $row['admin_display_name'] ?? null,
            balanceCents: (int) ($row['balance_cents'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'member_id' => $this->memberId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'collection_hold_reason' => $this->reason,
            'held_at' => DateFormatter::toUtcIso($this->heldAt),
            'held_by_admin_id' => $this->heldByAdminId,
            'held_by_admin_name' => $this->heldByAdminName,
            'balance_cents' => $this->balanceCents,
        ];
    }
}
