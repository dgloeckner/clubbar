<?php

declare(strict_types=1);

namespace App\Shared\Utils;

/**
 * How old somebody was at a given moment.
 *
 * Exists for the Jugendschutz check (ADR-0045), and deliberately takes the
 * moment as an argument rather than reading the clock. Rule 2 of that ADR:
 * *age is computed at the moment that matters* — the terminal at checkout, the
 * server at the transaction's own timestamp. A terminal may sync days after the
 * sale, and the question is whether the drink was lawful when it was handed
 * over, not whether the member has since had a birthday.
 */
final class Age
{
    /**
     * Full years elapsed between a birth date and a moment.
     *
     * Returns **null** rather than a number when the answer cannot be
     * established: an unreadable or absent birth date, or one that falls after
     * the moment. The caller decides what that means, and for the server it
     * means recording nothing — a violation asserted from a date it cannot read
     * would be a legal finding invented out of missing data.
     *
     * `DateTimeImmutable::diff()` does the arithmetic rather than a month/day
     * comparison, which is what makes 29 February come out right: a leapling is
     * a year older on 1 March in a common year, not on 28 February.
     */
    public static function yearsAt(?string $dateOfBirth, \DateTimeImmutable $moment): ?int
    {
        if ($dateOfBirth === null || trim($dateOfBirth) === '') {
            return null;
        }

        // Midnight, so the whole of the birthday counts as the day the member
        // reached the age — somebody who turns 18 today may buy today.
        $born = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($dateOfBirth), 0, 10));
        if ($born === false || \DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        if ($born > $moment) {
            return null;
        }

        return $born->diff($moment)->y;
    }
}
