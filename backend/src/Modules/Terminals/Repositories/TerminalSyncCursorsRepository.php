<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Repositories;

use App\Modules\Terminals\Enums\SyncStream;
use PDO;

/**
 * The delta cursor each terminal presents, and how it has moved (ADR-0041 §1).
 *
 * The high-water mark is the whole mechanism: an honest client's cursor is
 * monotonic non-decreasing, because the terminal stores whatever the server
 * returned and `SyncCursor::next()` never returns less than it was given. A
 * value below the mark means the client's state is not the state this token
 * has been building up here.
 */
class TerminalSyncCursorsRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * @return array{high_water_cursor: int, last_presented_cursor: int, regression_count: int}|null
     */
    public function find(string $terminalId, SyncStream $stream): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT high_water_cursor, last_presented_cursor, regression_count
               FROM terminal_sync_cursors
              WHERE terminal_id = :terminal_id AND stream = :stream
              LIMIT 1'
        );
        $stmt->execute(['terminal_id' => $terminalId, 'stream' => $stream->value]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'high_water_cursor' => (int) $row['high_water_cursor'],
            'last_presented_cursor' => (int) $row['last_presented_cursor'],
            'regression_count' => (int) $row['regression_count'],
        ];
    }

    /**
     * Record a cursor that did not go backwards.
     *
     * `GREATEST` rather than an assignment because two of the terminal's own
     * requests can be in flight at once; the mark is the highest value ever
     * seen, and a slower request carrying an older cursor must not lower it.
     */
    public function recordAdvance(string $terminalId, SyncStream $stream, int $presented): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO terminal_sync_cursors
                 (terminal_id, stream, high_water_cursor, last_presented_cursor)
             VALUES (:terminal_id, :stream, :high_water, :presented)
             ON DUPLICATE KEY UPDATE
                 high_water_cursor     = GREATEST(high_water_cursor, VALUES(high_water_cursor)),
                 last_presented_cursor = VALUES(last_presented_cursor)'
        );

        // Two placeholders for one value: prepares are not emulated on this
        // connection, so a named placeholder may not appear twice.
        $stmt->execute([
            'terminal_id' => $terminalId,
            'stream' => $stream->value,
            'high_water' => $presented,
            'presented' => $presented,
        ]);
    }

    /**
     * Record a cursor that went backwards.
     *
     * The mark itself is deliberately left where it was: it is the high-water
     * of what this token has ever been told, and a client presenting less does
     * not un-tell it. Lowering it here would let a forked pair ratchet the mark
     * down to the laggard's value and go quiet.
     */
    public function recordRegression(string $terminalId, SyncStream $stream, int $from, int $to, int $now): void
    {
        $stmt = $this->db->prepare(
            'UPDATE terminal_sync_cursors
                SET last_presented_cursor = :presented,
                    regression_count      = regression_count + 1,
                    last_regression_at    = :now,
                    last_regression_from  = :reg_from,
                    last_regression_to    = :reg_to
              WHERE terminal_id = :terminal_id AND stream = :stream'
        );

        $stmt->execute([
            'terminal_id' => $terminalId,
            'stream' => $stream->value,
            'reg_from' => $from,
            'reg_to' => $to,
            'presented' => $to,
            'now' => date('Y-m-d H:i:s', $now),
        ]);
    }

    /**
     * Streams that regressed within the window, for the cron tick.
     *
     * @return list<array{terminal_id: string, stream: string, last_regression_at: string, last_regression_from: int, last_regression_to: int, regression_count: int}>
     */
    public function regressionsSince(string $since): array
    {
        $stmt = $this->db->prepare(
            'SELECT terminal_id, stream, last_regression_at,
                    last_regression_from, last_regression_to, regression_count
               FROM terminal_sync_cursors
              WHERE last_regression_at IS NOT NULL AND last_regression_at >= :since
           ORDER BY terminal_id ASC, stream ASC'
        );
        $stmt->execute(['since' => $since]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'terminal_id' => (string) $row['terminal_id'],
                'stream' => (string) $row['stream'],
                'last_regression_at' => (string) $row['last_regression_at'],
                'last_regression_from' => (int) $row['last_regression_from'],
                'last_regression_to' => (int) $row['last_regression_to'],
                'regression_count' => (int) $row['regression_count'],
            ];
        }

        return $rows;
    }
}
