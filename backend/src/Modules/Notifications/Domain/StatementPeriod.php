<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

use App\Modules\Notifications\Enums\StatementCadence;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Which statement this is, and what instant its number is stated at
 * (ADR-0039 decisions 1 and 2).
 *
 * A Deckelauszug is anchored to the calendar rather than to anything that
 * happened, which is what lets a scheduled scan be idempotent for free: the
 * period key goes into `mail_outbox.dedup_key`, and `UNIQUE (kind, subject_id,
 * dedup_key)` then refuses a second August statement without anybody having to
 * look for the first one.
 *
 * Two ideas that are easy to conflate and are not the same:
 *
 * | | |
 * |---|---|
 * | **Key** | Which period this is — `2026-08`, `2026-Q3`. Identity, and the dedup key |
 * | **Boundary** | The instant the Deckel is stated at — the period's own start, `2026-08-01T00:00:00Z`. What the mail prints as „Stand: 1. August 2026" |
 *
 * So the August statement is sent *during* August and reports the tab as it
 * stood when August began. It says nothing about August itself, which is the
 * only arrangement in which the number is knowable when the message is queued.
 *
 * Pure: no clock, no I/O. Every entry point takes the instant it should reason
 * about, because {@see \App\Modules\Notifications\Services\PeriodicEnqueueService}
 * and its tests must be able to stand at a date rather than wait for one, and
 * this codebase has no `Clock` abstraction to inject
 * ({@see \App\Shared\Time\Utc} only pins the zone).
 */
final readonly class StatementPeriod
{
    private function __construct(
        public StatementCadence $cadence,
        private int $year,
        /** The period's first month, 1-based: 1..12 monthly, one of 1/4/7/10 quarterly. */
        private int $startMonth,
    ) {}

    /**
     * The period `$instant` falls inside.
     *
     * @throws InvalidArgumentException when the cadence is `off`. That is a
     *         caller error rather than a state to model: "no periods exist" and
     *         "here is the current one" are different answers, and returning a
     *         period for `off` would let a disabled installation enqueue.
     */
    public static function containing(StatementCadence $cadence, DateTimeImmutable $instant): self
    {
        if (!$cadence->isEnabled()) {
            throw new InvalidArgumentException('A disabled cadence has no periods; check isEnabled() first');
        }

        $utc = $instant->setTimezone(new DateTimeZone('UTC'));
        $year = (int) $utc->format('Y');
        $month = (int) $utc->format('n');

        return new self($cadence, $year, self::startMonthOf($cadence, $month));
    }

    /**
     * Parse a key an operator or a test named — `2026-08`, `2026-Q3`.
     *
     * Null rather than an exception for anything unparseable, including a key
     * of the wrong shape for this cadence: `--period 2026-Q3` on a monthly
     * installation is a mistake to report at the command line, not a stack
     * trace. A month outside 1..12 and a quarter outside 1..4 are rejected here
     * rather than normalised, because `2026-13` is far more likely to be a typo
     * for `2026-12` than a request for January 2027.
     */
    public static function fromKey(StatementCadence $cadence, string $key): ?self
    {
        if (!$cadence->isEnabled()) {
            return null;
        }

        $key = trim($key);

        if ($cadence === StatementCadence::QUARTERLY) {
            if (preg_match('/^(\d{4})-Q([1-4])$/', $key, $m) !== 1) {
                return null;
            }

            return new self($cadence, (int) $m[1], ((int) $m[2] - 1) * 3 + 1);
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $key, $m) !== 1) {
            return null;
        }

        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            return null;
        }

        return new self($cadence, (int) $m[1], $month);
    }

    /**
     * Read a key back out of a queued row, without being told the cadence.
     *
     * {@see fromKey()} is the *enqueue* direction and takes the cadence,
     * because there the cadence is the question: a monthly installation asked
     * for `2026-Q3` has made a mistake worth reporting. The send direction is
     * the opposite. `mail_outbox.dedup_key` was written when the row was
     * queued, and by the time the drain renders it the club may have switched
     * from monthly to quarterly — asking the *current* cadence to parse a key
     * written under the previous one would return null and leave a statement
     * that can never be rendered, permanently, for a setting change made after
     * it was queued.
     *
     * So the shape decides: `2026-Q3` is quarterly, `2026-08` is monthly. Both
     * are unambiguous, which is why the keys were given different shapes in the
     * first place.
     */
    public static function fromStoredKey(string $key): ?self
    {
        $cadence = str_contains($key, 'Q') ? StatementCadence::QUARTERLY : StatementCadence::MONTHLY;

        return self::fromKey($cadence, $key);
    }

    /** The dedup key. `2026-08` monthly, `2026-Q3` quarterly. */
    public function key(): string
    {
        if ($this->cadence === StatementCadence::QUARTERLY) {
            return sprintf('%04d-Q%d', $this->year, intdiv($this->startMonth - 1, 3) + 1);
        }

        return sprintf('%04d-%02d', $this->year, $this->startMonth);
    }

    /**
     * The instant the Deckel is stated at — midnight UTC on the period's first
     * day.
     *
     * UTC without qualification, and that is a decision rather than an
     * oversight: `Shared\Time\Utc` pins the whole system to it, so a boundary is
     * a plain instant and no daylight-saving transition can move it. A club in
     * Berlin therefore has statements dated 01:00 or 02:00 local, which nobody
     * sees — the mail prints a date, not a time.
     */
    public function boundary(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $this->year, $this->startMonth),
            new DateTimeZone('UTC'),
        );
    }

    /**
     * Is this still the period we are in at `$instant`?
     *
     * The catch-up cap, and the whole of it. A scheduler that was switched off
     * for a year must not produce twelve months of statements on the day it
     * comes back — each of those messages would state a tab from a boundary
     * long past, and the member would receive them all at once, in a queue that
     * would take a while to clear. Only the period we are actually in is
     * enqueueable; the ones that were missed stay missed.
     */
    public function isCurrentAt(DateTimeImmutable $instant): bool
    {
        return self::containing($this->cadence, $instant)->key() === $this->key();
    }

    private static function startMonthOf(StatementCadence $cadence, int $month): int
    {
        return $cadence === StatementCadence::QUARTERLY
            ? intdiv($month - 1, 3) * 3 + 1
            : $month;
    }
}
