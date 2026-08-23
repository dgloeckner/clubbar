<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\Domain;

/**
 * One already-resolved ceiling, and the band below it in which a member is
 * warned but still served.
 *
 * The rule is enforced at the terminal (`CreditLimitCheck` in
 * `terminal-frontend/lib/models/credit_limit.dart`, UC-T11 E3 / UC-T12): a
 * checkout that would push the tab *past* the ceiling is blocked, landing
 * exactly on it is allowed, and from the warning band upwards the member is
 * told. The terminal decides that in front of the member, offline, without
 * asking anyone — and this class never blocks anything. It exists so the
 * dashboard (#385) and the Deckelauszug (ADR-0039) can name the same line the
 * terminal is holding.
 *
 * **Resolved, not resolving.** Whose ceiling this is — the club's default or a
 * member's own — was decided by {@see CreditLimitPolicy::forMember()}, which is
 * the single place that rule is expressed on this side (ADR-0046 rule 1).
 * Instances of this class carry a number and answer questions about it.
 *
 * Sign convention follows the rest of the system: positive cents mean the
 * member owes the club, negative mean the club owes the member. Credit is
 * therefore never a limit problem.
 */
final readonly class CreditLimit
{
    public function __construct(
        /** The ceiling, in cents. Zero or less means no ceiling is enforced. */
        public int $limitCents,
        /** Share of the ceiling from which a member is warned but still served. */
        public int $warnThresholdPercent,
    ) {}

    /**
     * Whether a ceiling is enforced at all.
     *
     * Zero means unlimited — the convention `CreditLimitCheck` and
     * `DeckelStatementMail` already use, kept rather than replaced so that one
     * value means one thing across all three consumers (ADR-0046 decision 3).
     */
    public function isEnforced(): bool
    {
        return $this->limitCents > 0;
    }

    /**
     * The tab from which a member counts as close to their ceiling.
     *
     * Integer division, as at the terminal — the band boundary is a whole cent
     * and both sides must round it the same way, or the dashboard would list a
     * member the terminal has not warned yet.
     */
    public function warnAtCents(): int
    {
        return $this->isEnforced()
            ? intdiv($this->limitCents * $this->warnThresholdPercent, 100)
            : $this->limitCents;
    }

    /**
     * Where a tab sits relative to the ceiling.
     *
     * Landing exactly on the ceiling is still allowed (UC-T12 edge cases), so
     * only a tab strictly past it counts as exceeded.
     */
    public function status(int $balanceCents): CreditLimitStatus
    {
        if (!$this->isEnforced()) {
            return CreditLimitStatus::OK;
        }
        if ($balanceCents > $this->limitCents) {
            return CreditLimitStatus::EXCEEDED;
        }

        return $balanceCents >= $this->warnAtCents()
            ? CreditLimitStatus::APPROACHING
            : CreditLimitStatus::OK;
    }

    /**
     * How much of the ceiling a tab uses, as a whole percentage — the figure
     * the dashboard shows beside the amount, and what it ranks by once
     * ceilings differ between members. Rounded, and never below zero: a member
     * in credit uses none of their ceiling rather than a negative share.
     */
    public function percentOfLimit(int $balanceCents): int
    {
        if (!$this->isEnforced() || $balanceCents <= 0) {
            return 0;
        }

        return (int) round($balanceCents * 100 / $this->limitCents);
    }
}
