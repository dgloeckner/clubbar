<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\CreditLimits\Domain\CreditLimitStatus;

/**
 * One member on the near-limit digest: who, what they owe, and against what.
 *
 * The three figures the treasurer asked for travel together on purpose. A
 * balance without its ceiling is unreadable once ceilings differ per member
 * (ADR-0047) — €180 is comfortable against a €500 override and refused against
 * the €100 default — and the share is what makes a list of mixed ceilings
 * sortable by urgency rather than by size.
 */
final readonly class CreditLimitDigestLineDto
{
    public function __construct(
        public string $memberId,
        public string $name,
        /** What the member owes right now: the unsettled sum, positive means owed. */
        public int $balanceCents,
        /** The ceiling that actually applies to them — their override, or the club's. */
        public int $limitCents,
        /** How much of that ceiling the tab uses, whole percent. */
        public int $percentOfLimit,
        /** Whether the terminal is warning them or has already stopped serving them. */
        public CreditLimitStatus $status,
    ) {}
}
