<?php

declare(strict_types=1);

namespace App\Modules\Backups\Repositories;

use PDO;

/**
 * The `backup_config` singleton, mirroring `mail_config`.
 *
 * Part of #690, epic #686.
 */
class BackupConfigRepository
{
    public const SINGLETON_ID = 1;

    public function __construct(private PDO $db) {}

    /**
     * The row, or the migration's defaults if somebody deleted it.
     *
     * Never null, for the same reason `CronHeartbeatRepository` upserts: an
     * installation restored from a partial dump must degrade to the shipped
     * policy rather than to a fatal in a scheduled job nobody is watching.
     */
    public function get(): array
    {
        $row = $this->db->query(
            'SELECT * FROM backup_config WHERE id = ' . self::SINGLETON_ID
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? self::DEFAULTS : $row;
    }

    private const DEFAULTS = [
        'id' => self::SINGLETON_ID,
        'enabled' => 0,
        'cadence' => 'daily',
        'local_retention_days' => 30,
        'local_max_bytes' => 1073741824,
        'remote_retention_days' => 90,
        'budget_seconds' => 45,
    ];
}
