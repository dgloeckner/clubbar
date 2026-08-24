<?php

declare(strict_types=1);

namespace App\Modules\Backups\Repositories;

use PDO;

/**
 * `backup_runs` — one row per attempted backup, and **none of them is ever
 * deleted**.
 *
 * Pruning an artifact stamps `pruned_at` and keeps the row, because the row is
 * what answers *"which private keys do we still need?"* once the file is gone.
 * A rotation retires a key by waiting (ADR-0049 decision 3): archives sealed to
 * the old one age out on their own, and until the last of them has gone the
 * retired private key must not be discarded or those archives die silently.
 * `key_fingerprints` on a pruned row is what lets the panel say so, instead of
 * leaving a volunteer to guess.
 *
 * Part of #690, epic #686.
 */
class BackupRunsRepository
{
    public function __construct(private PDO $db) {}

    public function start(string $id, string $triggerSource, string $startedAt): void
    {
        $this->db->prepare(
            'INSERT INTO backup_runs (id, started_at, status, trigger_source)
             VALUES (?, ?, \'running\', ?)'
        )->execute([$id, $startedAt, $triggerSource]);
    }

    /**
     * @param list<string> $keyFingerprints
     * @param array<string, int> $tableManifest
     */
    public function markLocal(
        string $id,
        string $filename,
        int $bytes,
        string $sha256,
        array $keyFingerprints,
        array $tableManifest,
        string $finishedAt,
    ): void {
        $this->db->prepare(
            'UPDATE backup_runs
                SET status = \'local\', finished_at = ?, filename = ?, bytes = ?, sha256 = ?,
                    key_fingerprints = ?, table_manifest = ?
              WHERE id = ?'
        )->execute([
            $finishedAt,
            $filename,
            $bytes,
            $sha256,
            json_encode($keyFingerprints, JSON_THROW_ON_ERROR),
            json_encode($tableManifest, JSON_THROW_ON_ERROR),
            $id,
        ]);
    }

    /**
     * `last_error` is truncated to the column rather than allowed to overflow.
     *
     * A run fails once and is retried; what matters is that the *first* line of
     * the reason survives, and the full exception is already in the application
     * log with a stack trace. A silently dropped UPDATE would leave the row
     * reading `running` forever, which the staleness check would then report as
     * a stalled scheduler rather than as the failure it was.
     */
    public function markFailed(string $id, string $error, string $finishedAt): void
    {
        $this->db->prepare(
            'UPDATE backup_runs SET status = \'failed\', finished_at = ?, last_error = ? WHERE id = ?'
        )->execute([$finishedAt, mb_substr($error, 0, 1000), $id]);
    }

    /** The most recent run that produced an archive, in any state that still counts as one. */
    public function lastSuccessful(): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM backup_runs
              WHERE status IN (\'local\', \'uploaded\')
              ORDER BY started_at DESC, id DESC
              LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * When a run last *started*, successful or not.
     *
     * The minimum-interval guard keys on this rather than on success, because
     * the resource it protects — the webspace quota, and the CPU of a shared
     * tariff — is spent by an attempt, not by an outcome. Keying on success
     * would let a failing run be triggered in a loop.
     */
    public function lastStartedAt(): ?string
    {
        $value = $this->db->query('SELECT MAX(started_at) FROM backup_runs')->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Archives still on disk, oldest first — the order pruning removes them in.
     *
     * @return list<array<string, mixed>>
     */
    public function unprunedArchives(): array
    {
        return $this->db->query(
            'SELECT id, started_at, filename, bytes FROM backup_runs
              WHERE pruned_at IS NULL AND filename IS NOT NULL
                AND status IN (\'local\', \'uploaded\')
              ORDER BY started_at ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The artifact is gone; the row stays. */
    public function markPruned(string $id, string $prunedAt): void
    {
        $this->db->prepare('UPDATE backup_runs SET pruned_at = ? WHERE id = ?')
            ->execute([$prunedAt, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM backup_runs ORDER BY started_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
