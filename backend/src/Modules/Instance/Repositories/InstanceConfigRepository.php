<?php

declare(strict_types=1);

namespace App\Modules\Instance\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class InstanceConfigRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function getConfig(): ?array
    {
        $stmt = $this->db->query('SELECT * FROM instance_config WHERE id = 1');
        return $stmt->fetch() ?: null;
    }

    public function updateConfig(array $data, string $adminUserId): ?array
    {
        $allowed = ['instance_name'];
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = $adminUserId;
        $values[] = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("UPDATE instance_config SET {$set}, updated_by_admin_id = ?, updated_at = ? WHERE id = 1");
        $stmt->execute($values);

        $this->logger->info('Instance config updated');
        return $this->getConfig();
    }
}
