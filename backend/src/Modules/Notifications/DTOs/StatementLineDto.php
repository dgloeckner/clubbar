<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * One line of the Abrechnungsübersicht promised by Nutzungsordnung § 7 Abs. 1.
 *
 * Reconstructed from the settlement's items at send time, never stored
 * (ADR-0038 rule 5) — which is only safe because a settlement is append-only,
 * so what a member is told the amount consists of cannot drift afterwards.
 */
final readonly class StatementLineDto
{
    public function __construct(
        /** Product name, or the storno's note when there is no product. */
        public string $label,
        /** Booking date, `Y-m-d H:i:s` as stored. */
        public ?string $occurredAt,
        public int $amountCents,
    ) {}
}
