<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Enums;

/**
 * What the detector concluded about a terminal's credential (ADR-0041).
 *
 * Each kind has an innocent explanation, which is exactly why none of them
 * blocks anything: they are questions put to a human, not verdicts.
 */
enum TerminalAnomalyKind: string
{
    /**
     * Two source addresses active at the same time, sustained.
     *
     * A reconnect hands over cleanly — the old address stops, the new one
     * starts — and produces an overlap at or near zero. Two devices selling
     * through one evening produce hours of it. The innocent cases left are a
     * site whose dual uplink genuinely load-balances per connection, and an
     * IPv6 client rotating its interface identifier (ADR-0041 Consequences).
     */
    case CONCURRENT_IP = 'concurrent_ip';

    /**
     * A presented cursor below the high-water mark, but not zero.
     *
     * Innocently: the terminal was restored from a backup.
     */
    case CURSOR_REGRESSION = 'cursor_regression';

    /**
     * A presented cursor of zero while the high-water mark is not.
     *
     * This is what a thief holding only the token looks like — they cannot
     * know the cursor, so they start from nothing. It is also exactly what a
     * legitimate re-provisioning looks like, and the cursor alone cannot tell
     * them apart.
     */
    case CURSOR_RESET = 'cursor_reset';

    /**
     * Severity as the dashboard's alert bag understands it.
     *
     * Concurrent use is the only one of the three with no routine innocent
     * cause, so it is the one that reads as an error rather than a warning.
     */
    public function severity(): string
    {
        return $this === self::CONCURRENT_IP ? 'error' : 'warning';
    }
}
