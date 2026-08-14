<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Repositories;

use App\Modules\Notifications\Enums\DrainSource;
use PDO;

/**
 * The `cron_heartbeat` singleton — one row, written by every drain run.
 *
 * Three consumers, three different questions (see migration 025):
 *
 * - #405 asks *"has a scheduled run ever been observed?"* and gates finalize on
 *   it. Once `last_run_at` is set it stays set — a scheduler that dies later
 *   warns rather than locking the treasurer out of a collection.
 * - #406 asks *"is the queue moving?"* — this row plus the oldest pending
 *   message.
 * - #407 shows it as the self-check's diagnosis surface.
 *
 * Written on **every** run, including one that found nothing to do. An idle run
 * is the strongest evidence the scheduler works, and it is also the only
 * evidence available for eleven months of the year.
 */
class CronHeartbeatRepository
{
    public const SINGLETON_ID = 1;

    public function __construct(private PDO $db) {}

    /**
     * Stamp a completed run.
     *
     * `INSERT … ON DUPLICATE KEY UPDATE` rather than a plain `UPDATE`: the
     * migration seeds the row, but an installation restored from a partial dump
     * (or one whose row somebody deleted) must not silently stop recording — a
     * missing heartbeat reads as "the scheduler never ran", which is the one
     * conclusion that blocks finalize.
     *
     * @param string $phpVersion        The *drain's* interpreter, which is often not the web's
     * @param string $missingExtensions Comma-separated; `''` means checked and complete
     */
    public function record(
        DrainSource $source,
        int $sent,
        int $failed,
        string $phpVersion,
        string $missingExtensions,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO cron_heartbeat (id, last_run_at, source, sent, failed, php_version, missing_extensions)
             VALUES (?, NOW(), ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_run_at = VALUES(last_run_at),
                source = VALUES(source),
                sent = VALUES(sent),
                failed = VALUES(failed),
                php_version = VALUES(php_version),
                missing_extensions = VALUES(missing_extensions)'
        );

        $stmt->bindValue(1, self::SINGLETON_ID, PDO::PARAM_INT);
        $stmt->bindValue(2, $source->value);
        $stmt->bindValue(3, max(0, $sent), PDO::PARAM_INT);
        $stmt->bindValue(4, max(0, $failed), PDO::PARAM_INT);
        $stmt->bindValue(5, substr($phpVersion, 0, 32));
        $stmt->bindValue(6, substr($missingExtensions, 0, 255));
        $stmt->execute();
    }

    /** @return array<string,mixed>|null The row, or null on an installation that has none. */
    public function get(): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cron_heartbeat WHERE id = ?');
        $stmt->bindValue(1, self::SINGLETON_ID, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    /**
     * Has a drain run ever been observed?
     *
     * The question #405's install gate keys on, and the reason it is phrased as
     * "ever" rather than "recently": refusing to collect at the moment the
     * treasurer needs to is a worse failure than announcing late.
     */
    public function hasEverRun(): bool
    {
        $row = $this->get();

        return $row !== null && ($row['last_run_at'] ?? null) !== null;
    }
}
