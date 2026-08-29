<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

use App\Modules\Settlements\Domain\BlockedReason;
use App\Modules\Settlements\Enums\ReversalReason;
use App\Modules\Settlements\Enums\SettlementStatus;
use App\Shared\Utils\DateFormatter;

/**
 * One collection a bank reference resolved to (ADR-0032 §8, epic #433).
 *
 * The treasurer arrives holding a statement line and *not* knowing which run it
 * came from, so a candidate carries the four facts that let them match the row
 * against that line — member, amount, execution date and where the run stands —
 * plus whether the reversal they came to record is actually available.
 *
 * A candidate that cannot be acted on is still a candidate. `is_actionable` is
 * false and the reason says why, because the alternative — dropping it from the
 * list — tells a treasurer holding a real reference that it matches nothing,
 * and sends them off to record the return against something else.
 *
 * Nothing here is re-derived: `status`, `is_reversible` and
 * `reversal_blocked_reason` are the settlement's own answers, read from the
 * same gate the reverse endpoint throws from.
 */
final readonly class ReversalCandidateDto
{
    public function __construct(
        public string $settlementId,
        public string $memberId,
        public ?string $memberName,
        /** This member's total in this run — what a reversal would free. */
        public int $amountCents,
        public ?string $endToEndId,
        public ?string $mandateReference,
        public string $executionDate,
        public string $settlementDate,
        public SettlementStatus $status,
        public bool $isReversible,
        public ?BlockedReason $reversalBlockedReason,
        /** A colleague already recorded this one — the question actually asked. */
        public bool $alreadyReversed = false,
        public ?string $reversedAt = null,
        public ?string $reversedByAdminName = null,
        public ?ReversalReason $reversedReason = null,
    ) {}

    /** Whether recording a reversal against this collection would be accepted. */
    public function isActionable(): bool
    {
        return $this->isReversible && !$this->alreadyReversed;
    }

    public function toArray(): array
    {
        return [
            'settlement_id' => $this->settlementId,
            'member_id' => $this->memberId,
            'member_name' => $this->memberName,
            'amount_cents' => $this->amountCents,
            'amount_eur' => round($this->amountCents / 100, 2),
            'end_to_end_id' => $this->endToEndId,
            'mandate_reference' => $this->mandateReference,
            'execution_date' => $this->executionDate,
            'settlement_date' => $this->settlementDate,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_reversible' => $this->isReversible,
            'reversal_blocked_reason' => $this->reversalBlockedReason?->message,
            'reversal_blocked_code' => $this->reversalBlockedReason?->reason->value,
            'reversal_blocked_params' => $this->reversalBlockedReason?->params ?: null,
            'already_reversed' => $this->alreadyReversed,
            'reversed_at' => DateFormatter::toUtcIso($this->reversedAt),
            'reversed_by_admin_name' => $this->reversedByAdminName,
            'reversed_reason' => $this->reversedReason?->value,
            'is_actionable' => $this->isActionable(),
        ];
    }
}
