<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * How often this installation's scheduler actually fires (ADR-0039 decision 5).
 *
 * Every timing number in the mail feature is relative to this value rather than
 * to wall-clock minutes, because the drain cannot act between ticks however
 * short a backoff says it should. A retry scheduled fifteen minutes out on an
 * hourly cron is not a fifteen-minute retry; it is an hour-and-a-bit one, and
 * pretending otherwise makes both the ladder and the stall alarm describe a
 * machine we do not have.
 *
 * ### Why `weekly` is not a case
 *
 * ADR-0038 guarantees the Nutzungsordnung § 7 Abs. 3 announcement distance *at
 * enqueue*: the execution date is already at least seven days out when the row
 * is written, so any drain that runs before then keeps the promise. At a weekly
 * drain that argument collapses — a message enqueued an hour after a tick can
 * leave six days later and land essentially on the execution date. Rather than
 * support a granularity at which the feature quietly stops meaning what it
 * says, the value does not exist. {@see \App\Modules\Notifications\Controllers\MailConfigController}
 * refuses it by name with that reasoning; the column could not store it anyway.
 *
 * The escape hatch, named in ADR-0039 and deliberately not built: raise
 * `SettlementLeadTime::DAYS` to 14 on such a host so the announcement still
 * lands seven days ahead. That spends an ADR-0009 constant on a hosting
 * limitation and should wait until a real club needs it.
 */
enum CronInterval: string
{
    case HOURLY = 'hourly';
    case DAILY = 'daily';

    /** The value an installation that has never said anything is treated as. */
    public const DEFAULT = self::HOURLY;

    /**
     * A granularity that was asked for and is refused rather than unsupported.
     *
     * Kept as a constant so the API's refusal and this enum cannot drift into
     * disagreeing about which word it is.
     */
    public const REFUSED = 'weekly';

    /** One tick, in seconds — the unit every threshold in this feature counts in. */
    public function seconds(): int
    {
        return match ($this) {
            self::HOURLY => 3600,
            self::DAILY => 86400,
        };
    }

    /** $ticks ticks, in seconds. */
    public function ticks(int $ticks): int
    {
        return $this->seconds() * max(0, $ticks);
    }

    /**
     * Read a stored or submitted value, falling back rather than throwing.
     *
     * A row written before migration 029, a NULL from a partial restore and a
     * value this build does not know are the same situation: nothing reliable
     * was declared, and the drain still has to schedule a retry. The default is
     * the eager end of the range, so being wrong here means retrying sooner
     * than the cron can act rather than a ladder it is never awake for.
     */
    public static function fromDeclared(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::DEFAULT;
    }

    /**
     * Which declared interval an observed gap between runs looks like.
     *
     * Deliberately generous: a gap is classified as the largest interval it
     * comfortably exceeds, so an hourly cron whose ticks arrive a few minutes
     * late is still hourly. The point of the comparison is catching a crontab
     * that says hourly and fires *daily*, not auditing punctuality.
     *
     * Null when the gap is too short to conclude anything — a manual run
     * minutes after a scheduled one says nothing about the schedule.
     */
    public static function fromObservedGap(int $seconds): ?self
    {
        if ($seconds >= self::DAILY->seconds() * 0.75) {
            return self::DAILY;
        }

        if ($seconds >= self::HOURLY->seconds() * 0.75) {
            return self::HOURLY;
        }

        return null;
    }
}
