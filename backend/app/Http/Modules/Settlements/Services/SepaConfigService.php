<?php

namespace App\Http\Modules\Settlements\Services;

use App\Http\Modules\Settlements\DTOs\SepaConfigDto;
use App\Http\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Shared\Utils\IbanMasker;
use Illuminate\Support\Arr;

/**
 * SepaConfigService
 *
 * Manages SEPA configuration (creditor details) for XML generation.
 *
 * Implements ADR-0007: SEPA Configuration Management
 * Pattern 004: Service Layer
 * Pattern 010: Shared Base Service (without extending due to single-row nature)
 *
 * Key Business Rules:
 * - Single-row configuration (id=1)
 * - creditor_id is immutable (cannot be changed once set)
 * - All SEPA fields required for complete configuration
 */
final readonly class SepaConfigService
{
    /**
     * Initialize service with repository and audit service
     */
    public function __construct(
        private readonly SepaConfigRepository $repository,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Get SEPA configuration
     *
     * Returns masked DTO for security (creditor_id and IBAN are masked in responses)
     *
     * @return SepaConfigDto|null The configuration, or null if not found
     */
    public function getConfig(): ?SepaConfigDto
    {
        $config = $this->repository->getConfig();

        if (!$config) {
            return null;
        }

        return new SepaConfigDto(
            creditorId: $this->maskCreditorId($config->creditor_id),
            creditorName: $config->creditor_name,
            creditorIban: IbanMasker::mask($config->creditor_iban),
            creditorAddressStreet: $config->creditor_address_street,
            creditorAddressCity: $config->creditor_address_city,
            creditorAddressCountry: $config->creditor_address_country,
            isConfigured: $config->isConfigured(),
        );
    }

    /**
     * Update SEPA configuration
     *
     * Validates immutability of creditor_id (cannot be changed once set)
     * Logs all changes to audit log
     *
     * @param array $attributes Fields to update
     * @param string $adminUserId Admin performing the update
     * @return SepaConfigDto|null Updated configuration, or null if not found
     * @throws \Exception If creditor_id is being changed
     */
    public function updateConfig(array $attributes, string $adminUserId): ?SepaConfigDto
    {
        $currentConfig = $this->repository->getConfig();

        if (!$currentConfig) {
            return null;
        }

        // ADR-0007: creditor_id is immutable
        if (isset($attributes['creditor_id']) && $attributes['creditor_id'] !== $currentConfig->creditor_id) {
            if ($currentConfig->creditor_id !== null) {
                throw new \Exception('SEPA creditor ID cannot be changed once set');
            }
        }

        // Store old values for audit log (unmasked for full history)
        $oldValues = [
            'creditor_id' => $currentConfig->creditor_id,
            'creditor_name' => $currentConfig->creditor_name,
            'creditor_iban' => $currentConfig->creditor_iban,
            'creditor_address_street' => $currentConfig->creditor_address_street,
            'creditor_address_city' => $currentConfig->creditor_address_city,
            'creditor_address_country' => $currentConfig->creditor_address_country,
        ];

        // Update configuration
        $updated = $this->repository->updateConfig($attributes);

        if (!$updated) {
            return null;
        }

        // Log changes to audit log
        $newValues = array_merge($oldValues, Arr::only($attributes, array_keys($oldValues)));
        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::SEPA_CONFIG,
            entityId: '1',
            oldValues: $oldValues,
            newValues: $newValues,
            adminUserId: $adminUserId,
        );

        return new SepaConfigDto(
            creditorId: $this->maskCreditorId($updated->creditor_id),
            creditorName: $updated->creditor_name,
            creditorIban: IbanMasker::mask($updated->creditor_iban),
            creditorAddressStreet: $updated->creditor_address_street,
            creditorAddressCity: $updated->creditor_address_city,
            creditorAddressCountry: $updated->creditor_address_country,
            isConfigured: $updated->isConfigured(),
        );
    }

    /**
     * Check if SEPA configuration is complete
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->repository->isConfigured();
    }

    /**
     * Mask creditor ID for response (show only first and last 4 chars)
     *
     * Example: DE01ZZZ09999999999 → DE01****9999
     *
     * @param string|null $creditorId
     * @return string|null Masked ID or null if input is null
     */
    private function maskCreditorId(?string $creditorId): ?string
    {
        if (!$creditorId || strlen($creditorId) < 8) {
            return null;
        }

        $first = substr($creditorId, 0, 4);
        $last = substr($creditorId, -4);
        return $first . '****' . $last;
    }
}
