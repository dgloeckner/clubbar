<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\DTOs\SepaConfigDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Settlements\Repositories\SepaConfigRepository;

class SepaConfigService
{
    public function __construct(
        private SepaConfigRepository $sepaConfigRepository,
        private AuditService $auditService,
    ) {}

    public function getConfig(bool $masked = false): ?SepaConfigDto
    {
        $config = $this->sepaConfigRepository->getConfig();
        if (!$config) return null;
        return SepaConfigDto::fromRow($config, $masked);
    }

    public function updateConfig(array $attributes, string $adminUserId): ?SepaConfigDto
    {
        $old = $this->sepaConfigRepository->getConfig();
        $config = $this->sepaConfigRepository->updateConfig($attributes);

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::SEPA_CONFIG,
            entityId: '1',
            oldValues: $old ? ['creditor_name' => $old['creditor_name']] : null,
            newValues: ['creditor_name' => $config['creditor_name'] ?? null],
            adminUserId: $adminUserId,
        );

        return SepaConfigDto::fromRow($config);
    }

    public function isConfigured(): bool
    {
        return $this->sepaConfigRepository->isConfigured();
    }
}
