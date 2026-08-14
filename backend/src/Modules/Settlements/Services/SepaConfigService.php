<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\DTOs\SepaConfigDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Services\AuditService;

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

        if (!$config) {
            return null;
        }

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::SEPA_CONFIG,
            entityId: '1',
            oldValues: $old ? ['creditor_name' => $old['creditor_name']] : null,
            newValues: ['creditor_name' => $config['creditor_name'] ?? null],
            adminUserId: $adminUserId,
        );

        // Masked like the GET it mirrors — a save is not a reason to hand the
        // full creditor IBAN back to the browser (#392).
        return SepaConfigDto::fromRow($config, masked: true);
    }

    public function isConfigured(): bool
    {
        return $this->sepaConfigRepository->isConfigured();
    }
}
