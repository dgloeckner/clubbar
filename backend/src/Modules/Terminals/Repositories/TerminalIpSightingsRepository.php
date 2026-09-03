<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Repositories;

use App\Shared\Utils\DateFormatter;
use PDO;

/**
 * Where a terminal's authenticated requests came from (ADR-0041 §1).
 *
 * Written on the hottest path in the system — every authenticated terminal
 * request — so the write is one upsert against a primary key, in the same
 * class as the `UPDATE terminals SET last_sync_at` that already runs beside it.
 *
 * Read by the cron tick, which asks a question about *intervals* rather than
 * counts: which addresses were active at the same time as which others, and
 * for how long.
 */
class TerminalIpSightingsRepository
{
    /**
     * Seconds per bucket. Five minutes bounds an hour of one terminal's traffic
     * to twelve rows per address instead of ~300, and is still finer than the
     * fifteen-minute overlap the detector looks for.
     */
    public const BUCKET_SECONDS = 300;

    public function __construct(
        private PDO $db,
    ) {}

    /**
     * Record one authenticated request.
     *
     * `first_seen_at` is written once and never moved: the bucket's lower edge
     * is what an overlap calculation measures from. `last_seen_at` is pushed
     * with GREATEST rather than assigned, so an out-of-order write — a request
     * that took longer to reach here than a later one — cannot shrink an
     * interval that was already observed.
     */
    public function record(string $terminalId, string $ipAddress, int $now): void
    {
        $seenAt = date('Y-m-d H:i:s', $now);
        $bucketStart = date('Y-m-d H:i:s', self::bucketFor($now));

        $stmt = $this->db->prepare(
            'INSERT INTO terminal_ip_sightings
                 (terminal_id, ip_address, bucket_start, first_seen_at, last_seen_at, request_count)
             VALUES (:terminal_id, :ip, :bucket, :first_seen, :last_seen, 1)
             ON DUPLICATE KEY UPDATE
                 request_count = request_count + 1,
                 last_seen_at  = GREATEST(last_seen_at, VALUES(last_seen_at))'
        );

        // Two placeholders for one value: this connection does not emulate
        // prepares, and a named placeholder may not appear twice in a statement.
        $stmt->execute([
            'terminal_id' => $terminalId,
            'ip' => $ipAddress,
            'bucket' => $bucketStart,
            'first_seen' => $seenAt,
            'last_seen' => $seenAt,
        ]);
    }

    /**
     * One row per (terminal, address) active in the window, collapsed to the
     * interval it was active over.
     *
     * Collapsing in SQL rather than in PHP keeps the detector's working set to
     * the number of distinct addresses — a handful — instead of every bucket
     * row in the window.
     *
     * @return list<array{terminal_id: string, ip_address: string, first_seen_at: string, last_seen_at: string, request_count: int}>
     */
    public function activeIntervalsSince(string $since): array
    {
        $stmt = $this->db->prepare(
            'SELECT terminal_id,
                    ip_address,
                    MIN(first_seen_at) AS first_seen_at,
                    MAX(last_seen_at)  AS last_seen_at,
                    SUM(request_count) AS request_count
               FROM terminal_ip_sightings
              WHERE bucket_start >= :since
           GROUP BY terminal_id, ip_address
           ORDER BY terminal_id ASC, first_seen_at ASC'
        );
        $stmt->execute(['since' => $since]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'terminal_id' => (string) $row['terminal_id'],
                'ip_address' => (string) $row['ip_address'],
                'first_seen_at' => DateFormatter::toUtcIso((string) $row['first_seen_at'])
                    ?? (string) $row['first_seen_at'],
                'last_seen_at' => DateFormatter::toUtcIso((string) $row['last_seen_at'])
                    ?? (string) $row['last_seen_at'],
                'request_count' => (int) $row['request_count'],
            ];
        }

        return $rows;
    }

    /**
     * Drop sightings older than the retention window (ADR-0041 §5).
     *
     * @return int Rows removed.
     */
    public function pruneOlderThan(string $cutoff): int
    {
        $stmt = $this->db->prepare('DELETE FROM terminal_ip_sightings WHERE bucket_start < :cutoff');
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /** The 5-minute boundary at or before `$timestamp`. */
    public static function bucketFor(int $timestamp): int
    {
        return $timestamp - ($timestamp % self::BUCKET_SECONDS);
    }
}
