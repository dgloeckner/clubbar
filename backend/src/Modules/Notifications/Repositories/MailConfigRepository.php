<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

/**
 * The `mail_config` singleton (ADR-0038). Same shape as
 * InstanceConfigRepository and SepaConfigRepository — one row, id = 1,
 * whitelisted columns.
 */
class MailConfigRepository
{
    public const UPDATABLE_COLUMNS = [
        'sender_name',
        'sender_address',
        'reply_to_address',
        'header_style',
        'footer_org_name',
        'footer_address_line',
        'website_url',
        'logo_url',
        // Operational dials rather than branding, but they belong here for the
        // same reason the rest does: no secret in either, and a club on a
        // stricter relay should not have to edit a file to lower a batch size
        // (ADR-0039 decision 5).
        'cron_interval',
        'drain_batch_size',
    ];

    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function getConfig(): ?array
    {
        $stmt = $this->db->query('SELECT * FROM mail_config WHERE id = 1');
        return $stmt->fetch() ?: null;
    }

    public function updateConfig(array $data, string $adminUserId): ?array
    {
        [$set, $values] = SafeQuery::buildUpdate($data, self::UPDATABLE_COLUMNS);
        $values[] = $adminUserId;
        $values[] = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("UPDATE mail_config SET {$set}, updated_by_admin_id = ?, updated_at = ? WHERE id = 1");
        $stmt->execute($values);

        $this->logger->info('Mail config updated');
        return $this->getConfig();
    }

    /**
     * Store a freshly rotated secret's hash. Deliberately not routed through
     * {@see updateConfig()}: that path diffs `UPDATABLE_COLUMNS` into the
     * audit log, which is right for a batch size and wrong for anything that
     * authenticates a request — {@see \App\Modules\Notifications\Services\MailConfigService::rotateCronSecret()}
     * audits the *event* instead, with no secret material in it.
     */
    public function rotateCronSecret(string $hash, string $adminUserId): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE mail_config
                SET cron_secret_hash = ?, cron_secret_rotated_at = ?, updated_by_admin_id = ?, updated_at = ?
              WHERE id = 1'
        );
        $stmt->execute([$hash, $now, $adminUserId, $now]);

        $this->logger->info('Cron secret rotated');
        return $this->getConfig();
    }
}
