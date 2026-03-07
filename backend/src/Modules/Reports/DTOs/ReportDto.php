<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTOs;

class ReportDto
{
    public function __construct(
        public readonly string $reportType,
        public readonly array $filters,
        public readonly int $totalRevenueCents,
        public readonly int $totalQuantity,
        public readonly int $transactionCount,
        public readonly int $avgTransactionCents,
        /** @var ReportRowDto[] */
        public readonly array $data,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {}

    public function toArray(): array
    {
        return [
            'metadata' => [
                'report_type' => $this->reportType,
                'generated_at' => date('c'),
                'filters' => $this->filters,
            ],
            'summary' => [
                'total_revenue_cents' => $this->totalRevenueCents,
                'total_quantity' => $this->totalQuantity,
                'transaction_count' => $this->transactionCount,
                'avg_transaction_cents' => $this->avgTransactionCents,
            ],
            'data' => array_map(fn(ReportRowDto $row) => $row->toArray(), $this->data),
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => (int) ceil($this->total / max($this->perPage, 1)),
            ],
        ];
    }
}
