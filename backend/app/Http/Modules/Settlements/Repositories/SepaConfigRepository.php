<?php

namespace App\Http\Modules\Settlements\Repositories;

use App\Models\SepaConfig;
use App\Shared\Repositories\BaseRepository;

/**
 * SepaConfigRepository
 *
 * Data access layer for SEPA configuration.
 * Manages the single-row sepa_config table.
 *
 * Pattern 005: Repository Interface
 * Pattern 011: Shared Base Repository
 * Implements ADR-0007: SEPA Configuration Management
 */
class SepaConfigRepository extends BaseRepository
{
    /**
     * Initialize repository with SepaConfig model.
     */
    public function __construct()
    {
        parent::__construct(new SepaConfig());
    }

    /**
     * Get the SEPA configuration (always ID 1)
     *
     * @return SepaConfig|null The configuration row, or null if not found
     */
    public function getConfig(): ?SepaConfig
    {
        return $this->findById(1);
    }

    /**
     * Update the SEPA configuration
     *
     * @param array $attributes Configuration attributes to update
     * @return SepaConfig|null The updated configuration, or null if not found
     */
    public function updateConfig(array $attributes): ?SepaConfig
    {
        return $this->updateById(1, $attributes);
    }

    /**
     * Check if SEPA configuration is complete
     *
     * A configuration is complete when all required fields are set.
     *
     * @return bool True if configuration is complete
     */
    public function isConfigured(): bool
    {
        $config = $this->getConfig();

        if (!$config) {
            return false;
        }

        return $config->isConfigured();
    }
}
