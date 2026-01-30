<?php

namespace App\Http\Modules\Settlements\DTOs;

/**
 * SettlementDto - Settlement Response
 *
 * Encapsulates complete settlement information including items.
 * Used for list, detail, and create/update responses.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
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

    /**
     * Convert to array for JSON serialization
     *
     * Includes all settlement data and line items.
     * Amounts shown in both cents (exact) and EUR (display).
     *
     * @return array
     */
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
            'items' => $this->items,
            'created_at' => $this->createdAt,
            'created_by_admin_id' => $this->createdByAdminId,
            'created_by_admin_name' => $this->createdByAdminName,
        ];
    }
}
