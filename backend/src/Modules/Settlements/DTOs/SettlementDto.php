<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

use App\Modules\Settlements\Domain\CancellationGate;
use App\Modules\Settlements\Enums\SettlementMethod;

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
    ) {}

    public static function fromRow(array $row, array $items = []): self
    {
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
        ];
    }
}
