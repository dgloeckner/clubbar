<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Utils;

use App\Shared\Utils\Age;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How old somebody was on a given day (epic #582 M7, ADR-0045).
 *
 * The server recomputes this at **the transaction's own timestamp**, not at
 * ingest time (ADR-0045 rule 2). A terminal may sync days or weeks after the
 * sale, and the question a Jugendschutz check answers is whether the sale was
 * lawful *when it happened* — not whether the member has since had a birthday.
 *
 * Arithmetic worth its own tests because the whole detection rests on it, and
 * because the boundary is where this class of bug lives: somebody who turns 18
 * on the day of the sale is 18, and a check that says 17 refuses a lawful drink
 * — or, the same error the other way, records a violation that did not happen.
 */
class AgeTest extends TestCase
{
    private static function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function ages(): array
    {
        return [
            // birth date, moment, expected full years
            'the day before the eighteenth birthday' => ['2008-06-15', '2026-06-14 20:00:00', 17],
            'the eighteenth birthday itself' => ['2008-06-15', '2026-06-15 20:00:00', 18],
            'one minute past midnight on the birthday' => ['2008-06-15', '2026-06-15 00:01:00', 18],
            'the day after' => ['2008-06-15', '2026-06-16 20:00:00', 18],
            'comfortably adult' => ['1985-06-15', '2026-08-20 12:00:00', 41],
            'born today' => ['2026-08-20', '2026-08-20 12:00:00', 0],
            'the day before a birthday, a month out' => ['2010-12-31', '2026-12-30 23:59:59', 15],
            'that same birthday' => ['2010-12-31', '2026-12-31 00:00:00', 16],
        ];
    }

    #[DataProvider('ages')]
    public function test_full_years_between_a_birth_date_and_a_moment(
        string $dateOfBirth,
        string $moment,
        int $expected,
    ): void {
        $this->assertSame($expected, Age::yearsAt($dateOfBirth, self::at($moment)));
    }

    /**
     * 29 February is the one birth date the calendar does not repeat every year.
     *
     * PHP's own diff answers this the way German law does — a leapling has their
     * birthday on 1 March in a common year (§ 188 Abs. 3 BGB reasoning) — so on
     * 28 February they are still a year younger. Pinned because a hand-rolled
     * month/day comparison typically gets it wrong in the other direction.
     */
    public function test_a_leap_day_birth_date(): void
    {
        $this->assertSame(17, Age::yearsAt('2008-02-29', self::at('2026-02-28 12:00:00')));
        $this->assertSame(18, Age::yearsAt('2008-02-29', self::at('2026-03-01 12:00:00')));
    }

    /**
     * The sale is judged on the day it happened, not on the day the batch
     * arrives. This is the whole reason the check runs on `occurred_at`.
     */
    public function test_the_moment_is_what_decides(): void
    {
        $bornSixteenOn20260615 = '2010-06-15';

        // Sold the day before the birthday, synced a fortnight later.
        $this->assertSame(15, Age::yearsAt($bornSixteenOn20260615, self::at('2026-06-14 21:30:00')));
        // The same member, a day later, is old enough.
        $this->assertSame(16, Age::yearsAt($bornSixteenOn20260615, self::at('2026-06-15 21:30:00')));
    }

    /** A timestamp with a zone is read as the instant it names. */
    public function test_an_iso_timestamp_with_a_zone(): void
    {
        $this->assertSame(18, Age::yearsAt('2008-06-15', self::at('2026-06-15T18:00:00Z')));
    }

    /** @return array<string, array{0: string}> */
    public static function unusableBirthDates(): array
    {
        return [
            'empty' => [''],
            'prose' => ['sometime in the eighties'],
            'a non-existent day' => ['1990-02-30'],
        ];
    }

    /**
     * Null rather than a guess.
     *
     * The caller has to decide what an unknowable age means, and for the server
     * that is "record nothing" — asserting a violation from a date it cannot
     * read would be inventing a legal finding out of missing data.
     */
    #[DataProvider('unusableBirthDates')]
    public function test_an_unreadable_birth_date_yields_null(string $dateOfBirth): void
    {
        $this->assertNull(Age::yearsAt($dateOfBirth, self::at('2026-08-20 12:00:00')));
    }

    /**
     * A birth date after the moment is not a negative age — it is nonsense, and
     * nonsense is null. `past_date` (M2) keeps this out of the database on the
     * write path; a stored row predating that rule, or a transaction backdated
     * by a terminal with a wrong clock, could still reach here.
     */
    public function test_a_birth_date_after_the_moment_yields_null(): void
    {
        $this->assertNull(Age::yearsAt('2026-12-31', self::at('2026-08-20 12:00:00')));
    }
}
