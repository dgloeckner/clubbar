<?php

declare(strict_types=1);

namespace App\Shared\Time;

use DateTimeZone;
use InvalidArgumentException;

/**
 * Bucketing a UTC column by the club's calendar (#365, the aggregation half).
 *
 * {@see ClubTimeZone} converts one value at a time, which is all a mail or a
 * single field needs. An aggregate cannot work that way: `GROUP BY
 * DATE(occurred_at)` and `GROUP BY HOUR(occurred_at)` ask the *database* which
 * day and which hour a row belongs to, and the database only knows UTC. The
 * result is a chart that is smooth, plausible and shifted — the peak-hours
 * histogram reported 18:00 for a bar whose busiest hour was 20:00, and there is
 * no second surface for a reader to notice that against.
 *
 * ### Why not CONVERT_TZ
 *
 * `CONVERT_TZ(occurred_at, '+00:00', 'Europe/Berlin')` is the obvious answer and
 * it returns **NULL** on a stock MariaDB: named zones need the `mysql.time_zone`
 * tables, which a default install leaves empty and which we cannot arrange on
 * shared hosting. That is the same constraint {@see Utc::SQL_OFFSET} already
 * documents for `SET time_zone`. A NULL here would not error — it would empty
 * the chart, or worse, collapse every row into one bucket.
 *
 * ### What this builds instead
 *
 * The offset is not a constant (Berlin is +01:00 in winter and +02:00 in
 * summer), so a single `+ INTERVAL 7200 SECOND` is wrong for half the year and
 * for part of any range that straddles a transition. PHP's own zone database
 * knows every transition, so the expression carries them:
 *
 * ```sql
 * (t.occurred_at + INTERVAL CASE
 *      WHEN t.occurred_at < '2026-03-29 01:00:00' THEN 3600
 *      WHEN t.occurred_at < '2026-10-25 01:00:00' THEN 7200
 *      ELSE 3600 END SECOND)
 * ```
 *
 * Exact across daylight saving, no server configuration, and every existing
 * `DATE()` / `HOUR()` / `WEEK()` call keeps working — it is handed a local
 * instant instead of a UTC one. The `WHERE` clause still filters on the raw
 * column against UTC bounds, so the range scan keeps using the index; only the
 * grouping sees the shifted expression.
 */
final class ClubLocalSql
{
    /** A bare column or `alias.column`, which is all any caller here passes. */
    private const COLUMN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /**
     * Widest window considered when a caller has no bounds of its own.
     *
     * Every arm costs a `WHEN`, so an unbounded query cannot enumerate the
     * whole zone database; a couple of decades back and one year forward covers
     * any transaction a club actually holds and keeps the expression readable.
     */
    private const UNBOUNDED_YEARS_BACK = 20;
    private const UNBOUNDED_YEARS_FORWARD = 1;

    /**
     * A SQL expression evaluating `$column` — a UTC `DATETIME` — in the club's
     * zone, correct for every daylight-saving transition between the bounds.
     *
     * `$fromUtc` and `$toUtc` are the query's own UTC range in `Y-m-d H:i:s`;
     * pass null for an end that is unbounded. They only decide which
     * transitions the expression has to cover, so a range that is too wide
     * costs a few extra `WHEN` arms and a range that is too narrow is the one
     * thing that would be wrong — when in doubt, widen.
     */
    public static function localInstant(string $column, ?string $fromUtc = null, ?string $toUtc = null): string
    {
        if (preg_match(self::COLUMN, $column) !== 1) {
            throw new InvalidArgumentException(sprintf('Not a column reference: %s', $column));
        }

        $offsets = self::offsets($fromUtc, $toUtc);

        // One offset for the whole range — the common case for a dashboard
        // window, and for every range that does not cross March or October.
        if (count($offsets) === 1) {
            return sprintf('(%s + INTERVAL %d SECOND)', $column, $offsets[0]['offset']);
        }

        $sql = sprintf('(%s + INTERVAL CASE', $column);
        foreach ($offsets as $i => $entry) {
            if ($i === count($offsets) - 1) {
                $sql .= sprintf(' ELSE %d', $entry['offset']);
                break;
            }
            // The literal is produced by gmdate() from PHP's zone database, so
            // it cannot carry anything but digits, dashes and colons — there is
            // no caller-supplied text anywhere in this expression.
            $sql .= sprintf(
                " WHEN %s < '%s' THEN %d",
                $column,
                $offsets[$i + 1]['at'],
                $entry['offset'],
            );
        }

        return $sql . ' END SECOND)';
    }

    /**
     * The offsets in force across the range, oldest first.
     *
     * Each entry is the UTC instant the offset takes effect at (`at`, unused
     * for the first) and the offset in seconds. `getTransitions()` always
     * returns the state at `$begin` as its first element, so the list is never
     * empty and the first arm needs no lower bound.
     *
     * @return list<array{at: string, offset: int}>
     */
    private static function offsets(?string $fromUtc, ?string $toUtc): array
    {
        $now = time();
        $begin = self::timestamp($fromUtc) ?? strtotime(sprintf('-%d years', self::UNBOUNDED_YEARS_BACK), $now);
        $end = self::timestamp($toUtc) ?? strtotime(sprintf('+%d years', self::UNBOUNDED_YEARS_FORWARD), $now);

        if ($end < $begin) {
            $end = $begin;
        }

        $transitions = ClubTimeZone::zone()->getTransitions($begin, $end);
        if ($transitions === false || $transitions === []) {
            // A zone with no transition data at all is a fixed offset.
            $fixed = (new \DateTimeImmutable('@' . $begin))->setTimezone(ClubTimeZone::zone())->getOffset();

            return [['at' => gmdate('Y-m-d H:i:s', $begin), 'offset' => $fixed]];
        }

        return array_values(array_map(
            static fn(array $t): array => [
                'at' => gmdate('Y-m-d H:i:s', (int) $t['ts']),
                'offset' => (int) $t['offset'],
            ],
            $transitions,
        ));
    }

    /** A `Y-m-d H:i:s` or `Y-m-d` UTC bound as a unix timestamp, or null. */
    private static function timestamp(?string $utc): ?int
    {
        $utc = trim((string) $utc);
        if ($utc === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
