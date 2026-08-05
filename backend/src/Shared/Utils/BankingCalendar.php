<?php

declare(strict_types=1);

namespace App\Shared\Utils;

/**
 * TARGET2 banking calendar for SEPA execution dates.
 *
 * SEPA requires the requested collection date (ReqdColltnDt) to be a bank
 * business day. TARGET2 — the settlement system behind SEPA — is open Monday
 * to Friday except for six fixed closing days:
 *
 *   1 January, Good Friday, Easter Monday, 1 May, 25 December, 26 December
 *
 * That set has been unchanged since the ECB fixed it in 2002, so the dates are
 * computed rather than stored (no bank_holidays table — see ADR-0009).
 *
 * Regional bank holidays are deliberately not considered: TARGET2 governs SEPA
 * settlement, and local closures do not move the collection date.
 */
final class BankingCalendar
{
    /**
     * Is this date one on which TARGET2 settles?
     *
     * @param string $date Any date string PHP can parse; a time component is ignored.
     * @throws \InvalidArgumentException when the date cannot be parsed.
     */
    public static function isBusinessDay(string $date): bool
    {
        $day = self::parse($date);

        // ISO-8601 day of week: 6 = Saturday, 7 = Sunday.
        if ((int) $day->format('N') >= 6) {
            return false;
        }

        return !in_array(
            $day->format('Y-m-d'),
            self::target2Holidays((int) $day->format('Y')),
            true
        );
    }

    /**
     * The given date, or the first business day after it.
     *
     * Rolls across chained non-business days (Good Friday through Easter Monday
     * is four in a row) and across the year boundary.
     *
     * @return string ISO date (Y-m-d)
     * @throws \InvalidArgumentException when the date cannot be parsed.
     */
    public static function nextBusinessDay(string $date): string
    {
        $day = self::parse($date);

        // Bounded to keep a bug from spinning forever; the longest real run of
        // closing days is four (Good Friday → Easter Monday).
        for ($i = 0; $i < 14; $i++) {
            $iso = $day->format('Y-m-d');
            if (self::isBusinessDay($iso)) {
                return $iso;
            }
            $day = $day->modify('+1 day');
        }

        throw new \LogicException("No business day found within 14 days of {$date}");
    }

    /**
     * The six TARGET2 closing days of a year, as ISO dates in ascending order.
     *
     * @return list<string>
     */
    public static function target2Holidays(int $year): array
    {
        $easter = new \DateTimeImmutable(self::easterSunday($year));

        $holidays = [
            sprintf('%04d-01-01', $year),
            $easter->modify('-2 days')->format('Y-m-d'), // Good Friday
            $easter->modify('+1 day')->format('Y-m-d'),  // Easter Monday
            sprintf('%04d-05-01', $year),
            sprintf('%04d-12-25', $year),
            sprintf('%04d-12-26', $year),
        ];

        sort($holidays);

        return $holidays;
    }

    /**
     * Easter Sunday in the Gregorian calendar.
     *
     * Anonymous Gregorian algorithm (Meeus/Jones/Butcher). Implemented here
     * rather than via ext-calendar's easter_days(), which is not declared in
     * composer.json and cannot be assumed on shared hosting.
     *
     * @return string ISO date (Y-m-d)
     */
    public static function easterSunday(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function parse(string $date): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($date);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid date: {$date}", 0, $e);
        }
    }
}
