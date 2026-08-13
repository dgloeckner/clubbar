<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Domain;

/**
 * The ceiling a member's Deckel may reach before the terminal stops serving
 * them, and the band below it in which they are warned but still served.
 *
 * The rule itself is enforced at the terminal (`CreditLimitCheck` in
 * `terminal-frontend/lib/models/credit_limit.dart`, UC-T11 E3 / UC-T12): a
 * checkout that would push the tab *past* the limit is blocked, landing exactly
 * on it is allowed, and from the warning band upwards the member is told. The
 * terminal decides that in front of the member, offline, without asking anyone.
 *
 * This class exists so the admin dashboard can name the same line without
 * guessing where it is (#385). It answers only the backward-looking question —
 * where does a tab that already exists sit? — and never blocks anything.
 *
 * **The two constants below are a copy of the terminal's `AppConfig`**
 * (`balanceLimitCents`, `balanceWarnThresholdPercent`) and must be changed
 * together with it. Until the limit is configurable per instance there is
 * nowhere for a single copy to live: the terminal has to work with no backend
 * reachable, so it cannot read the value from here.
 *
 * Sign convention follows the rest of the system: positive cents mean the
 * member owes the club, negative mean the club owes the member. Credit is
 * therefore never a limit problem.
 */
final class CreditLimit
{
    /** The ceiling, in cents. Zero or less turns enforcement off. */
    public const LIMIT_CENTS = 10_000; // €100.00

    /** Share of the limit from which a member is warned but still served. */
    public const WARN_THRESHOLD_PERCENT = 80; // €80.00 of €100.00

    /** Comfortably inside the limit. */
    public const STATUS_OK = 'ok';

    /** Inside the limit but in the warning band. */
    public const STATUS_APPROACHING = 'approaching';

    /** Past the limit — the terminal refuses the next checkout. */
    public const STATUS_EXCEEDED = 'exceeded';

    /**
     * Whether a limit is enforced at all.
     */
    public static function isEnforced(): bool
    {
        return self::LIMIT_CENTS > 0;
    }

    /**
     * The tab from which a member counts as close to their limit.
     *
     * Integer division, as at the terminal — the band boundary is a whole cent
     * and both sides must round it the same way, or the dashboard would list a
     * member the terminal has not warned yet.
     */
    public static function warnAtCents(): int
    {
        return self::isEnforced()
            ? intdiv(self::LIMIT_CENTS * self::WARN_THRESHOLD_PERCENT, 100)
            : self::LIMIT_CENTS;
    }

    /**
     * Where a tab sits relative to the limit.
     *
     * Landing exactly on the limit is still allowed (UC-T12 edge cases), so
     * only a tab strictly past it counts as exceeded.
     */
    public static function status(int $balanceCents): string
    {
        if (!self::isEnforced()) {
            return self::STATUS_OK;
        }
        if ($balanceCents > self::LIMIT_CENTS) {
            return self::STATUS_EXCEEDED;
        }

        return $balanceCents >= self::warnAtCents()
            ? self::STATUS_APPROACHING
            : self::STATUS_OK;
    }

    /**
     * How much of the limit a tab uses, as a whole percentage — the figure the
     * dashboard shows beside the amount. Rounded, and never below zero: a
     * member in credit uses none of their limit rather than a negative share.
     */
    public static function percentOfLimit(int $balanceCents): int
    {
        if (!self::isEnforced() || $balanceCents <= 0) {
            return 0;
        }

        return (int) round($balanceCents * 100 / self::LIMIT_CENTS);
    }
}
