<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class SettlementDto
{
    public function __construct(
        public string $id,
        public ?string $manualReason,
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
    ) {}

    public static function fromRow(array $row, array $items = []): self
    {
        return new self(
            id: $row['id'],
            manualReason: $row['manual_reason'] ?? null,
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
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'manual_reason' => $this->manualReason,
            'settlement_date' => $this->settlementDate,
            'execution_date' => $this->executionDate,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'sepa_message_id' => $this->sepaMessageId,
            'total_amount_cents' => $this->totalAmountCents,
            'total_amount_eur' => round($this->totalAmountCents / 100, 2),
            'member_count' => $this->memberCount,
            'is_cancelled' => $this->isCancelled,
            'cancelled_at' => $this->cancelledAt,
            'exported_at' => $this->exportedAt,
            'notes' => $this->notes,
            'items' => array_map(fn($i) => $i instanceof SettlementItemDto ? $i->toArray() : $i, $this->items),
            'created_at' => $this->createdAt,
            'created_by_admin_id' => $this->createdByAdminId,
            'created_by_admin_name' => $this->createdByAdminName,
        ];
    }
}
