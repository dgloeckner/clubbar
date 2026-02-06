<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class SepaConfigRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function getConfig(): ?array
    {
        $stmt = $this->db->query('SELECT * FROM sepa_config WHERE id = 1');
        return $stmt->fetch() ?: null;
    }

    public function updateConfig(array $data): ?array
    {
        $allowed = ['creditor_id', 'creditor_name', 'creditor_iban', 'creditor_address_street', 'creditor_address_city', 'creditor_address_country', 'payment_reference_prefix'];
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("UPDATE sepa_config SET {$set}, updated_at = ? WHERE id = 1");
        $stmt->execute($values);

        $this->logger->info('SEPA config updated');
        return $this->getConfig();
    }

    public function isConfigured(): bool
    {
        $config = $this->getConfig();
        return $config
            && !empty($config['creditor_id'])
            && !empty($config['creditor_name'])
            && !empty($config['creditor_iban']);
    }
}
