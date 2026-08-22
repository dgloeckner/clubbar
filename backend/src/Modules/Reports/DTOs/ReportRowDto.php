<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTOs;

class ReportRowDto
{
    /**
     * @param ?string $dimension The group's name, or null when it has none —
     *        a sale whose product no longer resolves (ADR-0033 §1 allows one).
     *        Null rather than a placeholder word: the API is language-agnostic,
     *        so the label an admin reads is the frontend's to choose.
     * @param ?string $dimensionId The id the group keys on — the product,
     *        category or member. Null for the date groupings, which key on a
     *        derived value rather than an entity. It is what distinguishes two
     *        nameless groups from each other on screen.
     */
    public function __construct(
        public readonly ?string $dimension,
        public readonly ?string $dimensionId,
        public readonly int $revenueCents,
        public readonly int $count,
        public readonly float $percentOfTotal,
    ) {}

    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension,
            'dimension_id' => $this->dimensionId,
            'revenue_cents' => $this->revenueCents,
            'count' => $this->count,
            'percent_of_total' => round($this->percentOfTotal, 2),
        ];
    }
}
