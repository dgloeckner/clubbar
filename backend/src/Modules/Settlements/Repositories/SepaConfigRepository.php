<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class SepaConfigRepository
{
    /**
     * `getConfig()` is read once per outbox row by `SettlementMailBuilder` —
     * every settlement announcement needs the creditor block — so a drain
     * clearing a real backlog reads this identical, unchanging row hundreds
     * of times in one batch. Cached for the lifetime of this instance, which
     * is the lifetime of one request or one CLI run (`ServiceFactory::resolve()`
     * hands out a fresh instance every time), so this can never see a
     * different request's write. `$loaded` rather than `??=`: a genuinely
     * unconfigured installation caches `null` too, correctly, instead of
     * re-querying every call.
     */
    private ?array $cachedConfig = null;
    private bool $loaded = false;

    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function getConfig(): ?array
    {
        if (!$this->loaded) {
            $stmt = $this->db->query('SELECT * FROM sepa_config WHERE id = 1');
            $this->cachedConfig = $stmt->fetch() ?: null;
            $this->loaded = true;
        }

        return $this->cachedConfig;
    }

    public function updateConfig(array $data): ?array
    {
        $allowed = ['creditor_id', 'creditor_name', 'creditor_iban', 'creditor_address_street', 'creditor_address_city', 'creditor_address_country', 'payment_reference_prefix', 'mandate_template_url'];
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("UPDATE sepa_config SET {$set}, updated_at = ? WHERE id = 1");
        $stmt->execute($values);

        $this->logger->info('SEPA config updated');
        $this->loaded = false;

        return $this->getConfig();
    }

    public function isConfigured(): bool
    {
        $config = $this->getConfig();
        return $config
            && !empty($config['creditor_id'])
            && !empty($config['creditor_name'])
            && !empty($config['creditor_iban'])
            && !empty($config['mandate_template_url']);
    }
}
