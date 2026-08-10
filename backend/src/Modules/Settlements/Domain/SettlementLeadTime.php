<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain;

use App\Shared\Utils\BankingCalendar;

/**
 * The SEPA pre-notification lead time (ADR-0009), anchored on the server clock.
 *
 * **The anchor is today, and only today** (issue #113). The rule used to be
 * implemented as `execution_date >= settlement_date + 7 days`, where
 * `settlement_date` arrived in the request body — so a caller that backdated it
 * bought itself an execution date tomorrow, and the pre-notification period the
 * rule exists to guarantee was gone. The same anchor is now used by the
 * validation and by `GET /admin/settlements/execution-date-info`, so what the
 * server suggests is by construction what the server accepts.
 *
 * `settlement_date` no longer takes part: it is the settlement's creation date,
 * recorded by the server, and is not an input to any rule.
 */
final class SettlementLeadTime
{
    /** Fixed SEPA lead time in calendar days (ADR-0009). */
    public const DAYS = 7;

    /**
     * The server's own calendar day — the one anchor for every rule below.
     *
     * @param string|null $today Injectable for tests; defaults to the current date.
     */
    public static function today(?string $today = null): string
    {
        return (new \DateTimeImmutable($today ?? 'today'))->format('Y-m-d');
    }

    /**
     * The earliest execution date the lead time alone allows.
     *
     * Calendar days, so this may land on a weekend or a TARGET2 closing day;
     * `earliestBusinessDay()` is what a client should be offered.
     */
    public static function earliestDate(?string $today = null): string
    {
        return (new \DateTimeImmutable(self::today($today)))
            ->modify('+' . self::DAYS . ' days')
            ->format('Y-m-d');
    }

    /**
     * The earliest execution date that satisfies both halves of ADR-0009 —
     * the lead time and the TARGET2 business-day rule.
     */
    public static function earliestBusinessDay(?string $today = null): string
    {
        return BankingCalendar::nextBusinessDay(self::earliestDate($today));
    }

    /** Does this execution date clear the lead time measured from today? */
    public static function isSatisfiedBy(string $executionDate, ?string $today = null): bool
    {
        return new \DateTimeImmutable($executionDate) >= new \DateTimeImmutable(self::earliestDate($today));
    }

    /**
     * Why an execution date inside the lead time was rejected.
     *
     * Names the earliest date that would be accepted, because "7 days" alone
     * leaves the caller to redo the arithmetic the server just did.
     */
    public static function violationMessage(?string $today = null): string
    {
        return 'execution_date must be at least ' . self::DAYS . ' days from today — '
            . self::earliestDate($today) . ' or later';
    }
}
