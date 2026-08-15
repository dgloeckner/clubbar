<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

use App\Modules\Notifications\DTOs\QueuedMailDto;
use App\Modules\Settlements\Domain\CancellationGate;
use App\Modules\Settlements\Domain\ReversalGate;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Enums\SettlementStatus;

final readonly class SettlementDto
{
    public function __construct(
        public string $id,
        public SettlementMethod $method,
        public string $settlementDate,
        public string $executionDate,
        public ?string $periodStart,
        public ?string $periodEnd,
        public ?string $sepaMessageId,
        public int $totalAmountCents,
        public int $memberCount,
        public bool $isCancelled,
        public ?string $cancelledAt,
        public ?string $exportedAt,
        public ?string $notes,
        public array $items,
        public string $createdAt,
        public ?string $createdByAdminId,
        public ?string $createdByAdminName,
        public int $transactionCount = 0,
        public ?string $transactionDateMin = null,
        public ?string $transactionDateMax = null,
        public ?string $submittedAt = null,
        public ?string $submittedByAdminId = null,
        /**
         * Whether an Undo would still be accepted (#81). Derived from the same
         * CancellationGate the service throws from, so the button the admin
         * sees and the answer the API gives cannot drift apart.
         */
        public bool $isCancellable = false,
        /** Why not, when it is not — shown instead of a bare disabled button. */
        public ?string $cancellationBlockedReason = null,
        /**
         * Where this settlement stands (ruling #148 §6). Derived from
         * `is_cancelled`, the reversal rows, `submitted_at` and `exported_at`
         * on every read — there is no status column, so nothing can drift.
         */
        public SettlementStatus $status = SettlementStatus::DRAFT,
        /** The mirror of `is_cancellable`: money moved, so it can come back. */
        public bool $isReversible = false,
        public ?string $reversalBlockedReason = null,
        /** How many of this settlement's members have been reversed. */
        public int $reversedMemberCount = 0,
        /**
         * The reversal events themselves, when the caller asked for one
         * settlement. Empty in list responses, which do not join them.
         *
         * @var list<SettlementReversalDto>
         */
        public array $reversals = [],
        /**
         * What was queued to announce this settlement, per member (#407).
         *
         * Rides along on the single-settlement read for the same reason
         * `reversals` does: the expandable breakdown answers "what happened to
         * this member", and *"the announcement bounced"* is part of that answer.
         * A second request per expanded row would be the alternative, and this
         * one is already being made.
         *
         * Empty in list responses. Best effort per ADR-0038 rule 6 — this is
         * the "never invisible" half of it.
         *
         * @var list<QueuedMailDto>
         */
        public array $notifications = [],
    ) {}

    /**
     * @param list<SettlementReversalDto> $reversals Only the single-settlement
     *        read passes these; the list endpoint does not join them.
     * @param list<QueuedMailDto> $notifications Likewise.
     */
    public static function fromRow(
        array $row,
        array $items = [],
        array $reversals = [],
        array $notifications = [],
    ): self {
        // Rows read outside SettlementsRepository (minimal fixtures, older
        // tests) carry no counts. Absent means none, which derives the same
        // status the settlement had before reversals existed.
        $reversedMemberCount = (int) ($row['reversal_member_count'] ?? count($reversals));
        $settledMemberCount = (int) ($row['settled_member_count'] ?? $row['member_count'] ?? 0);

        return new self(
            id: $row['id'],
            // Default to DIRECT_DEBIT for rows that predate #163's `method`
            // column (e.g. minimal fixtures in older tests).
            method: SettlementMethod::tryFrom($row['method'] ?? '') ?? SettlementMethod::DIRECT_DEBIT,
            settlementDate: $row['settlement_date'],
            executionDate: $row['execution_date'],
            periodStart: $row['period_start'] ?? null,
            periodEnd: $row['period_end'] ?? null,
            sepaMessageId: $row['sepa_message_id'] ?? null,
            totalAmountCents: (int) $row['total_amount_cents'],
            memberCount: (int) $row['member_count'],
            isCancelled: (bool) $row['is_cancelled'],
            cancelledAt: $row['cancelled_at'] ?? null,
            exportedAt: $row['exported_at'] ?? null,
            notes: $row['notes'] ?? null,
            items: $items,
            createdAt: $row['created_at'],
            createdByAdminId: $row['created_by_admin_id'] ?? null,
            createdByAdminName: $row['admin_display_name'] ?? null,
            transactionCount: (int) ($row['transaction_count'] ?? 0),
            transactionDateMin: $row['transaction_date_min'] ?? null,
            transactionDateMax: $row['transaction_date_max'] ?? null,
            submittedAt: $row['submitted_at'] ?? null,
            submittedByAdminId: $row['submitted_by_admin_id'] ?? null,
            isCancellable: CancellationGate::isCancellable($row),
            cancellationBlockedReason: CancellationGate::blocker($row),
            status: SettlementStatus::derive($row, $reversedMemberCount, $settledMemberCount),
            isReversible: ReversalGate::isReversible($row),
            reversalBlockedReason: ReversalGate::blocker($row),
            reversedMemberCount: $reversedMemberCount,
            reversals: $reversals,
            notifications: $notifications,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method->value,
            'settlement_date' => $this->settlementDate,
            'execution_date' => $this->executionDate,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'sepa_message_id' => $this->sepaMessageId,
            'total_amount_cents' => $this->totalAmountCents,
            'total_amount_eur' => round($this->totalAmountCents / 100, 2),
            'member_count' => $this->memberCount,
            'is_cancelled' => $this->isCancelled,
            'cancelled_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->cancelledAt),
            'exported_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->exportedAt),
            'notes' => $this->notes,
            'items' => array_map(fn($i) => $i instanceof SettlementItemDto ? $i->toArray() : $i, $this->items),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'created_by_admin_id' => $this->createdByAdminId,
            'created_by_admin_name' => $this->createdByAdminName,
            'transaction_count' => $this->transactionCount,
            'transaction_date_min' => \App\Shared\Utils\DateFormatter::toUtcIso($this->transactionDateMin),
            'transaction_date_max' => \App\Shared\Utils\DateFormatter::toUtcIso($this->transactionDateMax),
            'submitted_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->submittedAt),
            'submitted_by_admin_id' => $this->submittedByAdminId,
            'is_cancellable' => $this->isCancellable,
            'cancellation_blocked_reason' => $this->cancellationBlockedReason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_reversible' => $this->isReversible,
            'reversal_blocked_reason' => $this->reversalBlockedReason,
            'reversed_member_count' => $this->reversedMemberCount,
            'reversals' => array_map(
                static fn($r) => $r instanceof SettlementReversalDto ? $r->toArray() : $r,
                $this->reversals,
            ),
            'notifications' => array_map(
                static fn($n) => $n instanceof QueuedMailDto ? $n->toArray() : $n,
                $this->notifications,
            ),
        ];
    }
}
