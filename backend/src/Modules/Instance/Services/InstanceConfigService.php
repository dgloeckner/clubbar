<?php

declare(strict_types=1);

namespace App\Modules\Instance\Services;

use App\Modules\Instance\DTOs\InstanceConfigDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Instance\Repositories\InstanceConfigRepository;
use App\Shared\Services\AuditService;

class InstanceConfigService
{
    private const DEFAULT_NAME = 'Club Bar';

    public function __construct(
        private InstanceConfigRepository $instanceConfigRepository,
        private AuditService $auditService,
    ) {}

    public function getConfig(): InstanceConfigDto
    {
        $config = $this->instanceConfigRepository->getConfig();
        return InstanceConfigDto::fromRow($config ?? []);
    }

    /**
     * The instance name as used by callers that only need the string, such as
     * TotpService — no need to go through the DTO for a single field.
     */
    public function getInstanceName(): string
    {
        $config = $this->instanceConfigRepository->getConfig();
        $name = $config['instance_name'] ?? null;
        return $name !== null && $name !== '' ? $name : self::DEFAULT_NAME;
    }

    public function updateConfig(array $attributes, string $adminUserId): ?InstanceConfigDto
    {
        $old = $this->instanceConfigRepository->getConfig();
        $config = $this->instanceConfigRepository->updateConfig($attributes, $adminUserId);

        if (!$config) {
            return null;
        }

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::INSTANCE_CONFIG,
            entityId: '1',
            oldValues: $old ? ['instance_name' => $old['instance_name'] ?? null] : null,
            newValues: ['instance_name' => $config['instance_name'] ?? null],
            adminUserId: $adminUserId,
        );

        return InstanceConfigDto::fromRow($config);
    }
}
