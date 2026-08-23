<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\Repositories;

use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use PDO;

class CreditLimitConfigRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getConfig(): ?array
    {
        $stmt = $this->db->query('SELECT * FROM credit_limit_config WHERE id = 1');
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateConfig(array $data, string $adminUserId): ?array
    {
        $allowed = ['default_limit_cents', 'warn_threshold_percent'];
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = $adminUserId;
        $values[] = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "UPDATE credit_limit_config SET {$set}, updated_by_admin_id = ?, updated_at = ? WHERE id = 1"
        );
        $stmt->execute($values);

        $this->logger->info('Credit limit config updated');
        return $this->getConfig();
    }
}
