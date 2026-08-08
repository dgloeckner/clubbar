<?php

declare(strict_types=1);

namespace App\Shared\Sync;

/**
 * The delta-sync window shared by every syncable entity.
 *
 * MySQL `TIMESTAMP` resolves to whole seconds, so two writes landing in the
 * same second are indistinguishable to a cursor that stores one. The protocol
 * used to store the last row's `updated_at` and ask for `> cursor` next time,
 * which dropped every row written later in that same second — permanently,
 * because no later sync ever looks at that second again (#84). A terminal
 * could go on selling at a stale price with nothing to heal it.
 *
 * So the window is inclusive at the bottom, and the cursor only steps past a
 * second once that second is provably over. The price is that a row may be
 * delivered twice; sync payloads are keyed by id and applied as upserts on the
 * terminal, so a repeat is a no-op while a miss is a wrong price at the bar.
 */
final class SyncCursor
{
    /**
     * Inclusive lower bound of the delta window, as a MySQL datetime.
     *
     * Sub-second parts of the client's cursor are truncated rather than
     * rounded up: rounding up would skip the very rows this window exists to
     * catch.
     */
    public static function lowerBound(int $sinceMs): string
    {
        return date('Y-m-d H:i:s', intdiv($sinceMs, 1000));
    }

    /**
     * The cursor handed back to the client after a delta query.
     *
     * @param array<int, array<string, mixed>> $rows      rows the query returned
     * @param int                              $sinceMs   cursor the client sent
     * @param int                              $queriedAt Unix second in which the query was issued
     */
    public static function next(array $rows, int $sinceMs, int $queriedAt): int
    {
        // Nothing changed: echo the client's cursor back rather than jumping to
        // "now", so a row written while the query ran is still in the next window.
        if ($rows === []) {
            return $sinceMs;
        }

        $latest = 0;
        foreach ($rows as $row) {
            // A tombstone may carry only `deleted_at`; both columns are nullable,
            // and either one can be the newest thing about a row.
            $latest = max(
                $latest,
                self::toUnixSeconds($row['updated_at'] ?? null),
                self::toUnixSeconds($row['deleted_at'] ?? null),
            );
        }

        if ($latest === 0) {
            return $sinceMs;
        }

        // Step past the newest second only once it is behind us. While the query
        // ran inside that second another write could still land in it, and
        // stepping over it would lose that write for good.
        $cursorSeconds = $latest < $queriedAt ? $latest + 1 : $latest;

        // The cursor never walks backwards, so a client that polls in a tight
        // loop cannot be pushed into re-fetching an ever-growing window.
        return max($sinceMs, $cursorSeconds * 1000);
    }

    private static function toUnixSeconds(mixed $mysqlDateTime): int
    {
        if (!is_string($mysqlDateTime) || $mysqlDateTime === '') {
            return 0;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $mysqlDateTime);
        return $dt ? $dt->getTimestamp() : 0;
    }
}
