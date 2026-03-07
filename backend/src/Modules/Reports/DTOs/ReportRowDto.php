<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTOs;

class ReportRowDto
{
    public function __construct(
        public readonly string $dimension,
        public readonly int $revenueCents,
        public readonly int $quantity,
        public readonly int $count,
        public readonly float $percentOfTotal,
    ) {}

    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension,
            'revenue_cents' => $this->revenueCents,
            'quantity' => $this->quantity,
            'count' => $this->count,
            'percent_of_total' => round($this->percentOfTotal, 2),
        ];
    }
}
