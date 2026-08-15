<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Repositories;

use App\Modules\Terminals\Enums\TerminalAnomalyKind;
use PDO;

/**
 * What the detector concluded, and whether anybody has looked at it
 * (ADR-0041 §2).
 *
 * One open row per (terminal, kind). While a condition persists the row is
 * updated rather than duplicated, so an anomaly that lasts all evening is one
 * alert instead of one per cron tick.
 *
 * That rule is enforced here rather than by a unique index because the natural
 * key — (terminal_id, kind, still-open) — cannot be expressed in MySQL: NULL
 * never equals NULL, so a unique index over the nullable acknowledgement column
 * would permit unlimited open rows. The detector is the only writer and runs
 * under the cron tick's lock, single-threaded, which is what makes the
 * find-then-write below safe.
 */
class TerminalAnomaliesRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * @return array{id: string, occurrence_count: int, first_detected_at: string}|null
     */
    public function findOpen(string $terminalId, TerminalAnomalyKind $kind): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, occurrence_count, first_detected_at
               FROM terminal_anomalies
              WHERE terminal_id = :terminal_id
                AND kind = :kind
                AND acknowledged_at IS NULL
              LIMIT 1'
        );
        $stmt->execute(['terminal_id' => $terminalId, 'kind' => $kind->value]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'occurrence_count' => (int) $row['occurrence_count'],
            'first_detected_at' => (string) $row['first_detected_at'],
        ];
    }

    public function open(string $id, string $terminalId, TerminalAnomalyKind $kind, array $details, int $now): void
    {
        $at = date('Y-m-d H:i:s', $now);

        $stmt = $this->db->prepare(
            'INSERT INTO terminal_anomalies
                 (id, terminal_id, kind, first_detected_at, last_detected_at, occurrence_count, details)
             VALUES (:id, :terminal_id, :kind, :first_at, :last_at, 1, :details)'
        );

        // Two placeholders for one value: prepares are not emulated on this
        // connection, so a named placeholder may not appear twice.
        $stmt->execute([
            'id' => $id,
            'terminal_id' => $terminalId,
            'kind' => $kind->value,
            'first_at' => $at,
            'last_at' => $at,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * The condition is still true. Refresh the evidence and the stamp; the
     * `first_detected_at` stays where it was, because how long this has been
     * going on is the part an admin most wants to know.
     */
    public function touch(string $id, array $details, int $now): void
    {
        $stmt = $this->db->prepare(
            'UPDATE terminal_anomalies
                SET last_detected_at = :at,
                    occurrence_count = occurrence_count + 1,
                    details          = :details
              WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'at' => date('Y-m-d H:i:s', $now),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @return bool Whether this call was the one that acknowledged it — false
     *              when it was already acknowledged, so the caller does not
     *              write a second audit entry for the same act.
     */
    public function acknowledge(string $id, string $adminUserId, int $now): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE terminal_anomalies
                SET acknowledged_at = :at,
                    acknowledged_by_admin_id = :admin_id
              WHERE id = :id AND acknowledged_at IS NULL'
        );

        $stmt->execute([
            'id' => $id,
            'at' => date('Y-m-d H:i:s', $now),
            'admin_id' => $adminUserId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM terminal_anomalies WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Every unacknowledged anomaly, newest first — the dashboard's alert and
     * the terminals list both render from this one read.
     *
     * @return list<array<string, mixed>>
     */
    public function listOpen(): array
    {
        $stmt = $this->db->query(
            'SELECT a.*, t.name AS terminal_name
               FROM terminal_anomalies a
               JOIN terminals t ON t.id = a.terminal_id
              WHERE a.acknowledged_at IS NULL
           ORDER BY a.last_detected_at DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Open anomaly counts per terminal, for decorating a terminal list without
     * a query per row.
     *
     * @return array<string, int> terminal id => open anomaly count
     */
    public function openCountsByTerminal(): array
    {
        $stmt = $this->db->query(
            'SELECT terminal_id, COUNT(*) AS open_count
               FROM terminal_anomalies
              WHERE acknowledged_at IS NULL
           GROUP BY terminal_id'
        );

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['terminal_id']] = (int) $row['open_count'];
        }

        return $counts;
    }
}
